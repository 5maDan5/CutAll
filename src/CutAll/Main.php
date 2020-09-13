<?php

namespace CutAll;

use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\Listener;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\level\particle\DestroyBlockParticle;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    const DURABILITY_LEVEL = [0, 1, 2];

    /** @var int[] */
    private static $tools = [];
    /** @var int[] */
    private static $blocks = [];
    /** @var int[] */
    private static $leaves = [];

    /** @var string[] */
    private static $processing = [];
    
    /** @var string[] */
    private static $enableList = [];

    /** @var Config */
    private $config;

    /** @var bool */
    private $enableParticle;

    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->saveDefaultConfig();
        $this->reloadConfig();
        if(!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0744, true);
        $this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);

        $this->durability = self::DURABILITY_LEVEL[$this->config->get("durability")] ?? 0;
        $this->enableParticle = $this->config->get("enable.particle");
        $this->enableCreative = $this->config->get("enable.during.creative");
        $this->wrongWoodDestroy = $this->config->get("enable.wrong.wood.destroy");
        $this->leavesDestroy = $this->config->get("enable.leaves.destroy");
        $this->limit = $this->config->get("limit.count");
        $this->range = $this->config->get("leaves.range");
        $this->startMode = $this->config->get("start.mode");
        $this->underDestroy = $this->config->get("enable.under.destroy");
        $this->dropGather = $this->config->get("enable.drop.gather");

        self::$enableList = [];

        self::$processing = [];

        $this->initCommand();
        $this->initBlocks();
        $this->initLeaves();
        $this->initTools();
    }

    private function initBlocks(): void {
        $ids = explode(",", $this->config->get("block.ids"));
        self::$blocks = [];

        foreach ($ids as $key => $id) {
            $item = ItemFactory::fromString($id);
            self::$blocks[$item->getBlock()->getId()] = true;
        }
    }

    private function initLeaves(): void {
        $ids = explode(",", $this->config->get("leaves.ids"));
        self::$leaves = [];

        foreach ($ids as $key => $id) {
            $item = ItemFactory::fromString($id);
            self::$leaves[$item->getBlock()->getId()] = true;
        }
    }

    private function initTools(): void {
        $ids = explode(",", $this->config->get("item.ids"));
        self::$tools = [];

        foreach ($ids as $key => $id) {
            $item = ItemFactory::fromString($id);
            self::$tools[$item->getId()] = true;
        }
    }

    private function initCommand(): void {
        $name = $this->config->get("toggle.command");

        if (empty($name)) return;

        Server::getInstance()->getCommandMap()->register($this->getName(), new class($name) extends Command {
            function __construct(string $name) {
                Command::__construct($name, "CutAllを切り替えます");
        
                $this->setPermission("cutall.command");
            }
        
            public function execute(CommandSender $sender, string $commandLabel, array $args) {
                if (!$this->testPermission($sender)) {
                    return true;
                }

                if (!$sender instanceof Player) {
                    $sender->sendMessage("§cサーバーにログインしてください");
                    return true;
                }

                Main::toggle($sender);
            }
		});
    }

    public function onLogin(PlayerLoginEvent $event): void {
        self::$enableList[$event->getPlayer()->getName()] = $this->startMode;
    }

    /**
     * @ignoreCancelled false
     * @priority MONITOR
     */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $block = $event->getBlock();
        $player = $event->getPlayer();
        $name = $player->getName();
        $item = $event->getItem();

        if (!self::$enableList[$name]) return;

        if (!$this->isCutBlocks($block)) return;

        if (!$this->isCutTools($item)) return;

        if (!$this->enableCreative and $player->isCreative()) return;

        if (isset(self::$processing[$name])) return;

        self::$processing[$name] = true;
        
        $adjacentToLeaves = $this->cutAllWood($block, $player, $item);

        if ($this->leavesDestroy and $adjacentToLeaves !== null) $this->cutAllLeaves($adjacentToLeaves, $player, $item, $block);

        if ($this->durability === 0) $item->applyDamage(1);

        unset(self::$processing[$name]);
    }

    private function cutAllWood(Block $block, Player $player, Item &$item): ?array {
        $adjacentToLeaves = null;

        $id = $block->getId();

        $q = new \SplQueue();
        $q->enqueue($block);

        $player->getLevel()->setBlock($block, Block::get(Block::AIR));
        $y = $block->getY();

        $dropPos = null;
        if ($this->dropGather) $dropPos = $block->add(0.5, 0.5, 0.5);

        $count = 1;

        while (!$q->isEmpty()) {
            $b = $q->dequeue();

            for ($i = 0; $i <= 5; ++$i) {
                if ($count >= $this->limit) return null;

                $next = $b->getSide($i);

                if ($this->isCutLeaves($next) and $adjacentToLeaves === null) {
                    $adjacentToLeaves[] = $b;
                }

                if ($this->wrongWoodDestroy) {
                    if (!$this->isCutBlocks($next)) continue;
                } else {
                    if ($next->getId() !== $id) continue;
                }

                if (!$this->underDestroy and $next->getY() < $y) continue;
                
                if (!$this->useBreakOn($next, $item, $player, $this->enableParticle, $dropPos)) return null;
                
                ++$count;

                $q->enqueue($next);
            }
        }

        return $adjacentToLeaves;
    }

    private function cutAllLeaves(array $blocks, Player $player, Item &$item, Block $startBlock): void {
        $level = $player->getLevel();
        $boundY = $this->underDestroy ? -1 : $startBlock->getY();

        $dropPos = null;
        if ($this->dropGather) $dropPos = $startBlock->add(0.5, 0.5, 0.5);

        foreach ($blocks as $key => $block) {
            for ($x = 0 - $this->range; $x <= $this->range; ++$x) {
                for ($y = 0 - $this->range; $y <= $this->range; ++$y) {
                    if ($block->y + $y < $boundY) continue;

                    for ($z = 0 - $this->range; $z <= $this->range; ++$z) {
                        $b = $level->getBlock(new Vector3($block->x + $x, $block->y + $y, $block->z + $z));

                        if ($this->isCutLeaves($b)) {
                            if (!$this->useBreakOn($b, $item, $player, $this->enableParticle, $dropPos)) return;
                        }
                    }
                }
            }
        }
    }

    public static function toggle(Player $player): void {
        $name = $player->getName();
        self::$enableList[$name] = !self::$enableList[$name];
        $player->sendMessage("§oCutAll: §".(self::$enableList[$name] ? "eON" : "7OFF"));
    }

    private function isCutTools(Item $item): bool {
        $id = $item->getId();

        return isset(self::$tools[$id]);
    }

    private function isCutBlocks(Block $block): bool {
        $id = $block->getId();

        return isset(self::$blocks[$id]);
    }

    private function isCutLeaves(Block $block): bool {
        $id = $block->getId();

        return isset(self::$leaves[$id]);
    }

    public function useBreakOn(Vector3 $vector, Item &$item = null, Player $player = null, bool $createParticles = false, ?Vector3 $dropPos = null) : bool{
        $level = $player->getLevel();
		$target = $level->getBlock($vector);
		$affectedBlocks = $target->getAffectedBlocks();

		if ($item === null){
			$item = ItemFactory::get(Item::AIR, 0, 0);
		}

		$drops = [];
		if($player === null or !$player->isCreative()){
			$drops = array_merge(...array_map(function(Block $block) use ($item) : array{ return $block->getDrops($item); }, $affectedBlocks));
		}
		$ev = new BlockBreakEvent($player, $target, $item, $player->isCreative(), $drops);

		if($level->checkSpawnProtection($player, $target)){
			$ev->setCancelled(); //set it to cancelled so plugins can bypass this
		}

		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		$drops = $ev->getDrops();

		if ($createParticles) {
			$level->addParticle(new DestroyBlockParticle($target->add(0.5, 0.5, 0.5), $target));
		}

		$target->onBreak($item, $player);

		if (count($drops) > 0){
            if ($dropPos === null) $dropPos = $target->add(0.5, 0.5, 0.5);

			foreach ($drops as $drop) {
				if (!$drop->isNull()){
					$level->dropItem($dropPos, $drop);
				}
			}
        }

        if (!$item instanceof Durable) return true;

        switch ($this->durability) {
            case 0: return true;
            case 1:
                $item->applyDamage(1);
            return true;
            case 2:
                $item->applyDamage(1);
                if ($item->isBroken()) return false;
            return true;
        }
	}
}