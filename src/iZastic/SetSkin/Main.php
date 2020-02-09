<?php

declare(strict_types=1);

namespace iZastic\SetSkin;

use ErrorException;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Skin;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase implements Listener
{
    private $uuidURL = "https://api.mojang.com/users/profiles/minecraft/<username>";
    private $skinURL = "https://sessionserver.mojang.com/session/minecraft/profile/<uuid>";
    private $playerData = null;
    private $skinsDir = "";
    private $playerDataPath = "";

    public function onEnable()
    {
        $this->playerDataPath = $this->getDataFolder() . "players.json";
        $this->skinsDir = $this->getDataFolder() . "cache";

        if (file_exists($this->playerDataPath)) {
            $this->playerData = json_decode(file_get_contents($this->playerDataPath));
        } else {
            $this->playerData = (object) array();
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onDisable()
    {
        file_put_contents($this->playerDataPath, json_encode($this->playerData));
    }

    public function onJoin(PlayerJoinEvent $event)
    {
        $player = $event->getPlayer();
        $id = $player->getUniqueId()->toString();
        if (isset($this->playerData->{$id})) {
            $skinName = $this->playerData->{$id};
            $player->setSkin($this->createSkin($skinName));
            $player->sendSkin();
        }
    }

    public function onQuit(PlayerQuitEvent $event)
    {
        $id = $event->getPlayer()->getUniqueId()->toString();
        if (isset($this->playerData->{$id})) {
            file_put_contents($this->playerDataPath, json_encode($this->playerData));
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage("This command is only usable by players");
            return false;
        }

        if (empty($args)) {
            $sender->sendMessage("/setskin <username>");
            return false;
        }

        if (!file_exists($this->skinsDir)) {
            mkdir($this->skinsDir);
        }

        $username = $args[0];
        $cachedSkin = null;

        if (!file_exists($this->skinsDir . "/" . $username . ".png")) {
            try {
                $uuid = $this->getUUID($username);
                if ($uuid) {
                    $profile = $this->loadJSON(str_replace("<uuid>", $uuid, $this->skinURL));
                    if ($profile) {
                        $properties = json_decode(base64_decode($profile->properties[0]->value));
                        $skinUrl = $properties->textures->SKIN->url;
                        $cachedSkin = file_get_contents($skinUrl);
                        file_put_contents($this->skinsDir . "/" . $username . ".png", $cachedSkin);
                    }
                }
            } catch (ErrorException $ex) {
                $sender->sendMessage("Failed to load skin for $username");
            }
        }

        if (file_exists($this->skinsDir . "/" . $username . ".png")) {
            $sender->setSkin($this->createSkin($username));
            $this->playerData->{$sender->getUniqueId()->toString()} = $username;
            $sender->sendSkin();
        } else {
            $sender->sendMessage("Unable to set skin");
        }

        return true;
    }

    private function getUUID($username)
    {
        $user = $this->loadJSON(str_replace("<username>", $username, $this->uuidURL));
        if ($user) {
            return $user->id;
        }
        return null;
    }

    private function loadJSON($url)
    {
        $result = file_get_contents($url);
        if ($result) {
            return json_decode($result);
        }
        return null;
    }

    private function createSkin($username): Skin
    {
        $path = $this->skinsDir . "/" . $username . ".png";
        $img = @imagecreatefrompng($path);
        $bytes = '';
        $l = (int)@getimagesize($path)[1];
        for ($y = 0; $y < $l; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $rgba = @imagecolorat($img, $x, $y);
                $a = ((~((int)($rgba >> 24))) << 1) & 0xff;
                $r = ($rgba >> 16) & 0xff;
                $g = ($rgba >> 8) & 0xff;
                $b = $rgba & 0xff;
                $bytes .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }
        @imagedestroy($img);
        return new Skin($username, $bytes);
    }
}
