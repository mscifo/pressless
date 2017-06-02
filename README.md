# Pressless
[![serverless][badge-serverless]](http://www.serverless.com)
[![language][badge-php]](http://php.net)
[![language][badge-nodejs]](http://nodejs.org)
[![license][badge-license]](LICENSE)

A tool that migrates an existing Wordpress site into a fully functioning Serverless site, powered by AWS (Cloudfront, API Gateway, Lambda, S3).

**Latest version is on [master][git-repo]**.

# Usage
Install this project:
```
npm install -g pressless
```

## Running Pressless
```
pressless
```
```
                                         ___                              
                                        /\_ \                             
 _____    _ __     __     ____    ____  \//\ \       __     ____    ____  
/\ '__`\ /\`'__\ /'__`\  /',__\  /',__\   \ \ \    /'__`\  /',__\  /',__\ 
\ \ \L\ \\ \ \/ /\  __/ /\__, `\/\__, `\   \_\ \_ /\  __/ /\__, `\/\__, `\
 \ \ ,__/ \ \_\ \ \____\\/\____/\/\____/   /\____\\ \____\\/\____/\/\____/
  \ \ \/   \/_/  \/____/ \/___/  \/___/    \/____/ \/____/ \/___/  \/___/ 
   \ \_\                ---helping wordpress cost less--- 
    \/_/                                                                  


  Usage:  [options] [command]


  Commands:

    setup [options] <domain>     Setup pressless
    tls [options]                Setup TLS
    domain                       Setup domain
    copydb <dsn>                 Copy database
    deploy [options]             Deploy Wordpress via Serverless
    test <stage> <request_path>  Test the Serverless function

  Options:

    -h, --help  output usage information
```

# Thanks
* [Andy Raines][git-arains] for the inspiration and base for this project

[badge-serverless]:   http://public.serverless.com/badges/v3.svg
[badge-php]:     https://img.shields.io/badge/language-php-blue.svg
[badge-nodejs]:     https://img.shields.io/badge/language-nodejs-blue.svg
[badge-license]:      https://img.shields.io/badge/license-MIT-orange.svg

[git-repo]:      https://github.com/mscifo/pressless