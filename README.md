# Pressless

A tool that migrates an existing Wordpress site into a fully functioning Serverless site, powered by AWS (Cloudfront, API Gateway, Lambda, S3).


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