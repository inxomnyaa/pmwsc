# PocketMine Websocket Server Chat - _pmwsc_
Implementation of the Websocket Protocol to allow full-featured chatting with basic command support, message receiving and sending.
## Features
- Website in a Minecraft-style look with color format support (i.e. §c = light red)
- Low resource and network cost - uses only some kilobytes of data, and almost no CPU / RAM, no local storage
- Supports sending & receiving messages and basic commands
- No worrying about hosting or getting a web server, the plugin automatically starts up the website for you!
- Simple login & authentication using a random 5 char token
## Screenshots
| Screenshot | Description |
| --- | --- |
| ![The login interface of the website](https://github.com/thebigsmileXD/pmwsc/blob/master/resources/screenshots/web_chat.png) | The login interface of the website |
| ![The website and client next to each other](https://github.com/thebigsmileXD/pmwsc/blob/master/resources/screenshots/web_and_mc10.png) | The website and client next to each other |
## Commands
- `/websocket`: Sends you a new authentication code
## About the code
The code uses a modified version of the pmmp RCON implementation - but it is planned to migrate this to fully standalone code

Uses code from the PHP-Websockets repo: https://github.com/ghedipunk/PHP-Websockets. (TODO verify that the code is still used)
PHP-Websockets is coded and "Copyright (c) 2012, Adam Alexander". You can find the license in /resources/php_websockets_slicense.txt