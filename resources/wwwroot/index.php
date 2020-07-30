<?php require_once('setup.php'); ?>
<html lang="en">
<head><title><?= TITLE ?> - by XenialDan</title>
    <link rel="stylesheet" href="mcbe_ui_web/css/main.css">
    <link rel="stylesheet" href="mcbe_ui_web/css/MCPEGUIhd.css">
    <style type="text/css">
        #chat_container {
            overflow-y: auto;
            background-color: lightgrey;
            display: inline-grid;
            flex-basis: 100%;
            align-content: flex-end;
        }

        #chat {
            overflow-y: auto;
        }

        #msg {
            padding: 0;
            margin: 0;
            flex: 1 1 auto;
        }

        #send {
            width: auto;
            margin: 0;
            align-items: center;
            display: inherit;
        }

        #send::after {
            content: var(--icon_chat);
            position: inherit;
        }
    </style>
    <script type="text/javascript">
        let socket;

        function init() {
            $("chat").innerHTML = "";
            let username = $("name").value;
            let auth = $("auth").value;
            if (!username || !auth) {
                alert("Username and auth code may not be empty!");
                return;
            }
            if (auth.length !== 5) {
                alert("Auth code is too long or too short");
                return;
            }
            let host = "<?=WEBSOCKET_URL?>";
            $("tab-2").checked = true;
            $("tab-2").disabled = false;
            try {
                socket = new WebSocket(host);
                socket.onopen = function (msg) {
                    sendMessage("Successfully connected to " + this.url, this.readyState);
                    socket.send("U:" + username + "A:" + auth.toLowerCase());//TODO more secure authentication
                };
                socket.onmessage = function (msg) {
                    sendMessage(msg.data, this.readyState);
                };
                socket.onclose = function (msg) {
                    sendMessage("Disconnected " + msg.reason, this.readyState);
                };
                socket.onerror = function (msg) {
                    if (socket.readyState === WebSocket.CLOSED) sendMessage("Could not connect");
                    sendMessage("An error occurred, see browser console for details", this.readyState);
                };
            } catch (ex) {
                sendMessage(ex.message);
            }
            $("msg").focus();
        }

        function send() {
            let txt, msg;
            txt = $("msg");
            msg = txt.value;
            if (!msg) {
                sendMessage("Message can not be empty");
                return;
            }
            txt.value = "";
            txt.focus();
            try {
                if (socket != null) {
                    switch (socket.status) {
                        case WebSocket.CLOSED:
                            throw new Error("WebSocket connection is closed");
                        case WebSocket.CLOSING:
                            throw new Error("WebSocket connection is closing");
                        case WebSocket.CONNECTING:
                            throw new Error("WebSocket connection is establishing");
                    }
                    socket.send(msg);
                } else {
                    sendMessage("Not connected to any server!");
                }
            } catch (ex) {
                sendMessage(ex);
            }
        }

        function quit(silent = true) {
            if (socket != null) {
                socket.close();
                socket = null;
            }
            if (!silent) {
                $("tab-2").disabled = true;
                $("tab-1").checked = true;
            }
        }

        function reconnect() {
            quit();
            init();
        }

        function automaticScroll(element) {
            element.scrollTop = element.scrollTopMax;
        }

        function sendMessage(msg, status) {
            let chat = $("chat");
            let color = "color_format_dark_red";
            switch (status) {
                case WebSocket.OPEN:
                    color = "color_format_dark_green";
                    break;
                case WebSocket.CLOSING:
                    color = "color_format_gold";
                    break;
                case WebSocket.CONNECTING:
                    color = "color_format_dark_blue";
                    break;
                case WebSocket.CLOSED:
                    color = "color_format_red";
                    break;
                default:
            }
            if (status)
                chat.innerHTML += "[" + status + "]<span class='mcfont " + color + "'> > " + msg + "</span><br>";
            else
                chat.innerHTML += "[!!!]<span class='mcfont " + color + "'> > " + msg + "</span><br>";
            automaticScroll(chat);
        }

        function onkey(event) {
            if (event.keyCode === 13) send();
        }

        function onloginkey(event) {
            if (event.keyCode === 13) $("start").click();
        }

        // JQuery-alike id selector
        function $(id) {
            return document.getElementById(id);
        }
    </script>
</head>

<body class="mcfont">
<div class="tabs">
    <div class="tab icons icon_profile">
        <input type="radio" id="tab-1" name="tab-group" checked>
        <label for="tab-1">Login</label>
        <div class="content" style="padding: 0;">
            <div class="split-view horizontal">
                <div class="split left default_padding" style="text-align: center;">
                    <h2><?= TITLE ?></h2>
                    <input id="name" type="text" placeholder="Username" onkeypress="onloginkey(event)"
                           minlength="1"/><br>
                    <input id="auth" type="text" placeholder="Auth code" onkeypress="onloginkey(event)" minlength="5"
                           maxlength="5"/><br>
                    <button id="start" onclick="reconnect()">Start</button>
                </div>
                <div class="split right default_padding" style="width: 30%;text-align: center;">
                    <h3 class="color_format_yellow">What is this?</h3>
                    A browser chat for your PMMP server - with low data usage, easy setup and compatible with most
                    modern browsers<br>
                    <h3 class="color_format_yellow">Does your PMMP server also need this?</h3>
                    Then check out the repository on <a href="https://github.com/thebigsmilexd/pmwsc"
                                                        class="color_format_aqua">my GitHub</a>.
                    There you can find the source code, setup instructions, downloads and report issues.<br>
                    <br><i>Coded by XenialDan</i>
                </div>
            </div>
        </div>
    </div>
    <div class="tab icons icon_chat">
        <input type="radio" id="tab-2" name="tab-group" disabled>
        <label for="tab-2">Chat</label>
        <div class="content" style="padding: 0;">
            <div class="split-view horizontal">
                <div class="split left" style="display: flex;flex-direction: column;">
                    <div id="chat_container">
                        <div id="chat"></div>
                    </div>
                    <div style="flex: 0 0;display: inline-flex;">
                        <input id="msg" type="text" onkeypress="onkey(event)" placeholder="Message">
                        <button id="send" type="button" onclick="send()">Send&nbsp;</button>
                    </div>
                </div>
                <div class="split right default_padding" style="width: 30%;text-align: center;">
                    <!-- TODO add player list/server query addons -->
                    <!-- TODO allow adding custom buttons -->
                    <input type="button" onclick="quit(false)" value="Quit">
                    <br>
                    <input type="button" onclick="reconnect()" value="Reconnect">
                </div>
            </div>
        </div>
    </div>
    <div class="tab x">
        <label>
            <a onclick="quit(false)"></a>
        </label>
    </div>
</div>

</body>
</html>