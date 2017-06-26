# 0.6.3

## Bugs

* Fix for new database config not being properly written to wp-config.php.migration.

## Other

* Updated readme.
* Updated changelog.

# 0.6.2

## Features

* Refactored copydb command to remove dependency on mysql-tools package.

# 0.6.1

## Bugs

* Fixed some issues with the copydb command.

# 0.6.0

## Features

* Added serverless-apigw-binary plugin to setup binary media types.

# 0.5.5

## Features

* Renamed custom s3 deployment bucket from ${website_bucket}-sls-cfm to ${website_bucket}-deploys.

# 0.5.4

## Features

* Added support for checking dns for alternative website bucket domain (with or without www) and ouputing appropriate messaging.

# 0.5.3

## Bugs

* Fix reference to 'ServerlessDeploymentBucket', which is no longer used now that we specify a custom deployment bucket.

# 0.5.2

## Features

* Switch to using a custom s3 bucket for serverless deployment data.

## Bugs

* Make sure we create the custom s3 deployment bucket since serverless doesn't auto-create custom buckets.
* Switch to using a default stage/env of '0', since '_' is not a valid character for a serverless stack service name.  Also do proper validation on stage/env input.

# 0.5.1

## Features

* Switch to using ServerlessCustomDomain class for creating ApiGateway domain instead of calling serverless executable.
* Switch to a default stage/env of '_', since different stages/envs should be handled by specifying different domain names.

## Bugs

* Added missing logger when instantiating ServerlessCustomDomain.

# 0.5.0

## Features

* Autodetect status of required dns records and output appropriate messaging.
* Updated to latest version of serverless-domain-manager with some customizations.
* Check to see if ApiGateway domain already exists before creating.  Also perform creation as part of setup process.
* Output needed DNS records after deployment.

# 0.4.1

## Features

* Switch to using a runtime calculated variable for the stage option used in serverless-domain-manager.

# 0.4.0

## Features

* Removed tls command in favor of requesting a new AWS ACM certificate during setup.

## Other

* Removed todos in header.
* Updated description of domain command.

# 0.3.0

## Features

* Removed the env, region and verbose cli options for the setup command.  They are now specific to the deploy command, allowing different regions/environments to be deployed to regardless of what was specified during setup.
* Added domain validation during setup via tldjs.

# 0.2.0

## Features

* Check for missing composer dependencies and install/run composer.
* Switched to v2 of the AWS SDK, since v3 requires php >= 5.5.
* Allow for specifying php version to use from cli argument.  Defaults to php-5.4.45, since some older plugins use the mysql_ functions which were deprecated in php-5.5.
* Switched to populating pressless vars into a .env file which then gets injected into the php environment via the nodejs handler.
* Added functionality to cache output buffer to s3 and redirect to static site domain. Intended to be coupled with an s3 website error document redirect.
* Override common file functions in order to replace local paths with s3 paths since lambda filesystem is readonly.    
* Added zip extension to allowed list for serving static files.
* Refactored how we handle output.
* Removed inclusion of WP Static HTML Output plugin and replaced with Jetpack plugin.
* Improved support for Evolution.
* Added new php binary with simplexml and dom extensions.
* Added ability to specify ACM certificate name as cli option to be used by servless-domain-manager instead of trying to lookup the ACM certificate and match by domain name.
    
## Bugs

* Ensure we are setting cookies received from ApiGateway.
* Fix for ensuring we can send multiple cookie headers to ApiGateway.
* Ensure static xml files aren't converted to base64.
* Ensure that numeric values in / are converted to an int type.
* Detect if post body is base64-encoded, and decode if it is.
* Detect if a raw POST body is present and handle accoridingly.
* Ensure that multiple query string parameters of the same name with array notation are concatenated.
* Populated extra global vars that are needed by Wordpress.
* Added some missing extensions required by Wordpress.
* Some random wordpress issue fixes.
* Fixed issue that would cause 'serverless deploy' to fail if the service collective length of the provided domain/env/region was too long.

## Other

* Updated readme.

# 0.1.0

Initial release.