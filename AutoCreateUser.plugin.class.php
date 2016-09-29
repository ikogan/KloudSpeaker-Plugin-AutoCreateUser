<?php

/**
 * AutoCreateUser.plugin.class.php
 *
 * Copyright 2016 Ilya Kogan
 * Released under GPL License.
 */

class AutoCreateUser extends PluginBase {
    protected $adminGroups;
    protected $emailAttribute;
    protected $groupAttribute;
    protected $groupSeperator;
    protected $defaultFiles;

    public function __construct($env, $id, $settings) {
        parent::__construct($env, $id, $settings);

        $this -> adminGroups = $this -> getSetting("adminGroups", array());
        $this -> groupSeperator = $this -> getSetting("environmentGroupSeperator", ":");
        $this -> defaultFiles = $this -> getSetting("files", array());

        $this -> log("adminGroups set to " . serialize($this -> adminGroups));
        $this -> log("groupSeperator set to " . $this -> groupSeperator);
        $this -> log("files set to " . serialize($this -> defaultFiles));

        $attributeMapping = $this -> getSetting("environmentMapping");

        if($attributeMapping != NULL) {
            $this -> emailAttribute = array_key_exists("email", $attributeMapping) ? $attributeMapping["email"] : NULL;
            $this -> groupAttribute = array_key_exists("groups", $attributeMapping) ? $attributeMapping["groups"] : NULL;

            $this -> log("emailAttribute set to " . ($this -> emailAttribute != NULL ? $this -> emailAttribute : "NULL"));
            $this -> log("groupAttribute set to " . ($this -> groupAttribute != NULL ? $this -> groupAttribute : "NULL"));
        } else {
            $this -> log("environmentMapping not set.");
        }
    }

	public function version() {
		return "0_1";
	}

	public function versionHistory() {
		return array($this -> version());
	}

    // Unfortunately, the only place we can create users
    // before the system tries to use them is during setup.
    // Therefore, we're only going to do this if the "remote"
    // method is the only one enabled.
    // 
    // Note that this is a little convluted because we're trying
    // to minimize writes to the database. We do extra work here
    // to ensure that we only make changes if we really need to
    // since writes have the potentially to really slow down
    // responses (locks, etc.).
    public function setup() {
        $authMethods = $this -> env -> settings() -> setting("authentication_methods");
		if(!is_array($authMethods) || !in_array("remote", $authMethods) || count($authMethods) > 1) {
            return;
        }

        if(!isset($_SERVER['REMOTE_USER'])) {
            return;
        }

        $this -> env -> db() -> startTransaction();

        $user = $this -> env -> configuration() -> getUserByName($_SERVER['REMOTE_USER']);

        // Figure out what our actual list of groups is. We basically filter the groups list
        // to the intersection of all groups the user logged in with and all groups that are defined
        // in the system.
        $externalGroups = $this -> groupAttribute != NULL && isset($_SERVER[$this -> groupAttribute]) ?
            explode($this -> groupSeperator, $_SERVER[$this -> groupAttribute]) : array();
        if(Logging::isDebug()) {
            $this -> log("Received user groups " . serialize($externalGroups));
        }

        $internalGroups = $this -> env -> configuration() -> getAllUserGroups();
        $groups = array();
        $userType = NULL;
        foreach($internalGroups as $group) {
            if(in_array($group['name'], $externalGroups)) {
                array_push($groups, $group);

                // While we're here, figure out if the user is an admin.
                if(in_array($group['name'], $this -> adminGroups)) {
                    $userType = 'a';
                }
            }
        }

        if(Logging::isDebug()) {
            $this -> log("User's groups are " . serialize($groups));
        }

        // Get the user's email
        $email = $this -> emailAttribute != NULL && isset($_SERVER[$this -> emailAttribute])
            ? $_SERVER[$this -> emailAttribute] : NULL;

        $id = NULL;
        if($user == NULL) {
            // If we don't have a user, create one
            $this -> log("Creating new user " . $_SERVER['REMOTE_USER']);
            $id = $this -> env -> configuration() -> addUser($_SERVER['REMOTE_USER'], NULL, $email, $userType, NULL);
        } else if(strcmp($user['email'], $email) != 0 || strcmp($user['user_type'], $userType) != 0) {
            // If we do, but there are differences between what the user logged in
            // with and whats stored, update.
            $this -> log("Updating user " . $_SERVER['REMOTE_USER']);
            $result = $this -> env -> configuration() -> updateUser($user['id'],
                $user['name'],
                $user['lang'],
                $email,
                $userType,
                NULL);

            if($result) {
                $id = $user['id'];
            } else {
                throw new Exception("Failed updating user for some reason");
            }
        } else {
            $id = $user['id'];
        }

        $userGroups = $this -> env -> configuration() -> getUsersGroups($id);

        // Determine which groups we need to add users to and delete
        // users from.
        $toDelete = array_udiff($userGroups, $groups, function($a, $b) { return strcmp($a['name'], $b['name']); });
        $toAdd = array_udiff($groups, $userGroups, function($a, $b) { return strcmp($a['name'], $b['name']); });

        if(count($toDelete) > 0) {
            $this -> env -> configuration() -> removeUsersGroups($id, array_map(function($a) { return $a['id']; }, $toDelete));
        }

        if(count($toAdd) > 0) {
            $this -> env -> configuration() -> addUsersGroups($id, array_map(function($a) { return $a['id']; }, $toAdd));
        }

        // Determine whether or not we should delete folders
        // based on the user's groups.
        $folders = $this -> env -> configuration() -> getUserFolders($id);
        
        $toDelete = array();
        foreach($folders as $folder) {
            $configFolder = current(array_filter($this -> defaultFiles, function($a) use($folder) {
                return strcmp(str_replace('${username}', $_SERVER['REMOTE_USER'], $a['path']), $folder['path']) == 0;
            }));

            if($configFolder && array_key_exists("permissions", $configFolder)) {
                if(count(array_filter($groups, function($a) use($configFolder) { return in_array($a['name'], $configFolder['permissions']); })) == 0) {
                    array_push($toDelete, $folder);
                }
            } else if(count($configFolder) == 0) {
                array_push($toDelete, $folder);
            }
        }

        // Remove the folder from the user and delete if no users are using it
        if(count($toDelete) > 0) {
            foreach($toDelete as $folder) {
                $this -> env -> configuration() -> removeUserFolder($id, $folder['id']);
            }
        }

        // Determine whether or not we need to add any folders for this user
        $toAdd = array();
        foreach($this -> defaultFiles as $folder) {
            if(array_key_exists("permissions", $folder)) {
                if(current(array_filter($groups, function($a) use($folder) { return in_array($a['name'], $folder['permissions']); })) === FALSE) {
                    $this -> log("User does no have permission to access " . $folder['name']);
                    continue;
                }
            }

            $realPath = str_replace('${username}', $_SERVER['REMOTE_USER'], $folder['path']);

            $existing = current(array_filter($folders, function($a) use($realPath) { return strcmp($a['path'], $realPath) == 0; }));

            if($existing !== FALSE) {
                if(strcmp($existing['name'], $folder['name']) == 0) {
                    $this -> env -> configuration() -> updateFolder($existing['id'], $folder['name'], $existing['path']);
                }
            } else {
                array_push($toAdd, array('name' => $folder['name'], 'path' => $realPath));
            }
        }

        // Actually do the adding
        if(count($toAdd) > 0) {
            $this -> log("Adding user to " . serialize($toAdd));

            $allFolders = $this -> env -> configuration() -> getFolders();
            foreach($toAdd as $folder) {
                $existing = current(array_filter($allFolders, function($a) use($folder) { return strcmp($a['path'], $folder['path']) == 0; }));

                // We might have an existing folder, use that one, or create a new one
                if($existing === FALSE) {
                    $folderId = $this -> env -> configuration() -> addFolder($folder['name'], $folder['path']);
                } else {
                    $folderId = $existing['id'];
                }

                $this -> env -> configuration() -> addUserFolder($id, $folderId, NULL);
            }
        }

        $this -> env -> db() -> commit();
    }

	public function __toString() {
		return "AutoCreateUserPlugin";
	}

    function log($line = "") {
        if(Logging::isDebug()) {
            Logging::logDebug("PLUGIN (AutoCreateUser): $line");
        }
    }
}

?>
