<?php

namespace xenialdan\pmwsc\command;

use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;
use xenialdan\pmwsc\Loader;

class WebsocketCommand extends PluginCommand
{
    public function __construct(string $name, Plugin $owner)
    {
        parent::__construct($name, $owner);
        $this->setPermission('websocket');
        $this->setDescription('Generate a password for the websocket chat');
        $this->setUsage('/websocket');
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args)
    {
        /** @var Loader $plugin */
        $plugin = $this->getPlugin();
        if (!$plugin->isEnabled()) {
            return false;
        }

        if (!$this->testPermission($sender)) {
            $sender->sendMessage($this->getPermissionMessage());
            return false;
        }
        if (!$sender instanceof Player) return false;
        $password = Loader::getAuthCode($sender->getName());
        $sender->sendMessage('Your new login code for the websocket chat is: ' . TextFormat::GOLD . $password);
        return true;
    }
}