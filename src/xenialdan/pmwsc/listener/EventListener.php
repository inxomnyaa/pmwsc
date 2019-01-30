<?php


namespace xenialdan\pmwsc\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\plugin\Plugin;
use xenialdan\pmwsc\Loader;

class EventListener implements Listener
{
    public $owner;

    public function __construct(Plugin $plugin)
    {
        $this->owner = $plugin;
    }

    public function onMessage(PlayerChatEvent $event){
        Loader::getInstance()->websocketServer->broadcast($event->getPlayer()->getDisplayName(), $event->getMessage());
    }

    public function onLogin(PlayerLoginEvent $event){
        Loader::getAuthCode($event->getPlayer()->getName(), false);
    }

}