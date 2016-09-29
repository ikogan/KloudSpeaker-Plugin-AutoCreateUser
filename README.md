# KloudSpeaker-Plugin-AutoCreateUser

Small KloudSpeaker Plugin that automatically creates users when used with "remote" authentication.
During system initialization, if the "remote" authentication method is the only one enabled
and the `REMOTE_USER` server variable is set, the plugin will ensure that the user exists
and is in any groups and folders managed by the plugin.

## Installation

The best way to install this plugin is to create a directory for Kloudspeaker customizations
somewhere on your filesystem and define the `customizations_folder`,
`customizations_folder_url` properties in your configuration and configure this as a
custom plugin as in the below example (`custom => TRUE`), and place the
`AutoCreateUser.plugin.class.php` into `${YourCustomDirectory}/plugins/AutoCreateUser`. See
[Kloudspeaker's documentation](https://github.com/sjarvela/kloudspeaker/wiki/Customizing-resources) for more information.

Alternatively, simply place `AutoCreateUser.plugin.class.php` into
`${KLOUDSPEAKER_INSTALL_DIR}/backend/plugins/AutoCreateUser`.

*NOTE: The directory into which the php file is placed must be named `AutoCreateUser`*.

## Configuration

The plugin supports managing a user's groups and folders as well as the users themselves.
To do this, the attributes the server will set containing those groups must be defined,
as well as folder configurations to manage. For example:

```php
$CONFIGURATION = array(
    ...
    "plugins" => array(
        ...
        "AutoCreateUser" => array(
            "custom"                => TRUE,
            "adminGroups"           => array('admins'),
            "environmentMapping"    => array(
                "email"     => "REMOTE_USER_EMAIL",
                "groups"    => "REMOTE_USER_GROUPS"
            ),
            "environmentGroupSeperator"  => ":",
            "files" => (
                array(
                    "name" => "Home",
                    "path" => '/home/${username}'
                ),
                array(
                    "name" => "Shared",
                    "path" => "/data/shared",
                    "permissions" => array('shared-users')
                )
            )
        )
    )
)
```

*NOTE: Groups defined here must also exist as Kloudspeaker groups or they will be ignored,
with the exception of the `adminGroups` option.*

### Admin Groups (adminGroups)

List of all groups in which user's should automatically be granted admin rights to
Kloudspeaker. Note that users *not* in these groups will have their admin rights
*removed*.

### Emvironment Mapping (environmentMapping)

Hash of user attributes and the server environment variable in which they will appear.
The only two currently supported are `email` and `groups`.

### Environment Group Seperator (environmentGroupSeperator)

This plugin expects all groups to appear in one string in the variable defined in
`environmentMapping['groups']`. Use this option to define how those groups will
split in the string.

### Files (files)

Hash of all folders that should be automatically created (and removed) for each user.
Each entry has 3 options:

*Name*: The name used for display purposes.
*Path*: The local system path for the folder. Note that `${username}` will be expanded
        to the value of `REMOTE_USER`.
*Permissions*: Optional. Lists groups in which a user must be in order to get this folder
        created. Users in _any_ of the listed groups will be added to the folder. If they
        removed from the groups, they will be removed from these folders.

## Example `mod_auth_mellon` Configuration

```apache
Alias / "/var/www/html/kloudspeaker/"

RewriteEngine on
RewriteCond %{HTTPS} off
RewriteRule ^/?(.*) https://%{SERVER_NAME}/$1 [R,L]

<Location "/">
    Require valid-user
    AuthType "mellon"

    MellonEnable "auth"
    MellonVariable "KLOUDSPEAKER_MELLON_AUTH"
    MellonSecureCookie On
    MellonEndpointPath "/saml2"
    MellonDefaultLoginPath "/"

    MellonSPentityId "https://www.yourserver.com"
    MellonSPCertFile /etc/httpd/mellon/sp/kloudspeaker-cert.pem
    MellonSPPrivateKeyFile /etc/httpd/mellon/sp/kloudspeaker-key.pem
    MellonIdPMetadataFile /etc/httpd/mellon/idp/youridp.xml

    MellonSetEnvNoPrefix "REMOTE_USER_EMAIL" "mail"
    MellonSetEnvNoPrefix "REMOTE_USER_GROUPS" "groups"
    MellonMergeEnvVars On ":"
</Location>

...
```

For more information, see [mod_auth_mellon's documentation](https://github.com/UNINETT/mod_auth_mellon).
