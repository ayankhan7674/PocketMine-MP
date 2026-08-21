<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\event\player\PlayerDuplicateLoginEvent;
use pocketmine\event\player\PlayerResourcePackOfferEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\form\Form;
use pocketmine\item\Item;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Translatable;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\FilterNoisyPacketException;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\encryption\DecryptionException;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\encryption\PrepareEncryptionTask;
use pocketmine\network\mcpe\handler\DeathPacketHandler;
use pocketmine\network\mcpe\handler\HandshakePacketHandler;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\handler\PacketHandlerAction;
use pocketmine\network\mcpe\handler\PacketHandlerInspector;
use pocketmine\network\mcpe\handler\PreSpawnPacketHandler;
use pocketmine\network\mcpe\handler\ResourcePacksPacketHandler;
use pocketmine\network\mcpe\handler\SessionStartPacketHandler;
use pocketmine\network\mcpe\handler\SpawnResponsePacketHandler;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ClientboundCloseFormPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\OpenSignPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayerStartItemCooldownPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\ServerToClientHandshakePacket;
use pocketmine\network\mcpe\protocol\SetDifficultyPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\ToastRequestPacket;
use pocketmine\network\mcpe\protocol\TransferPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\network\mcpe\protocol\UpdateAdventureSettingsPacket;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\UsedChunkStatus;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\ObjectSet;
use pocketmine\utils\TextFormat;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function array_map;
use function array_slice;
use function array_values;
use function base64_encode;
use function bin2hex;
use function count;
use function get_class;
use function implode;
use function in_array;
use function is_string;
use function json_encode;
use function ord;
use function random_bytes;
use function str_split;
use function strcasecmp;
use function strlen;
use function strtolower;
use function substr;
use function time;
use function ucfirst;
use const JSON_THROW_ON_ERROR;

class NetworkSession{
    private const INCOMING_PACKET_BATCH_PER_TICK = 2; //usually max 1 per tick, but transactions arrive separately
    private const INCOMING_PACKET_BATCH_BUFFER_TICKS = 100; //enough to account for a 5-second lag spike

    private const INCOMING_GAME_PACKETS_PER_TICK = 2;
    private const INCOMING_GAME_PACKETS_BUFFER_TICKS = 100;

    private const INCOMING_PACKET_BATCH_HARD_LIMIT = 300;

    private PacketRateLimiter $packetBatchLimiter;
    private PacketRateLimiter $gamePacketLimiter;

    private \PrefixedLogger $logger;
    private ?Player $player = null;
    private ?PlayerInfo $info = null;
    private ?int $ping = null;

    private ?PacketHandler $handler = null;
    /**
     * @var PacketHandlerAction[]|null
     * @phpstan-var array<class-string<Packet>, PacketHandlerAction>|null
     */
    private ?array $handlerActions = null;

    private bool $connected = true;
    private bool $disconnectGuard = false;
    private bool $loggedIn = false;
    private bool $authenticated = false;
    private int $connectTime;
    private ?CompoundTag $cachedOfflinePlayerData = null;

    private ?EncryptionContext $cipher = null;

    /**
     * @var string[]
     * @phpstan-var list<string>
     */
    private array $sendBuffer = [];
    /**
     * @var PromiseResolver[]
     * @phpstan-var list<PromiseResolver<true>>
     */
    private array $sendBufferAckPromises = [];

    /** @phpstan-var \SplQueue<array{CompressBatchPromise|string, list<PromiseResolver<true>>, bool}> */
    private \SplQueue $compressedQueue;
    private bool $forceAsyncCompression = true;
    private bool $enableCompression = false; //disabled until handshake completed

    private int $nextAckReceiptId = 0;
    /**
     * @var PromiseResolver[][]
     * @phpstan-var array<int, list<PromiseResolver<true>>>
     */
    private array $ackPromisesByReceiptId = [];

    private ?InventoryManager $invManager = null;

    /**
     * @var \Closure[]|ObjectSet
     * @phpstan-var ObjectSet<\Closure() : void>
     */
    private ObjectSet $disposeHooks;

    private string $noisyPacketBuffer = "";
    private int $noisyPacketsDropped = 0;

    public function __construct(
        private Server $server,
        private NetworkSessionManager $manager,
        private PacketPool $packetPool,
        private PacketSender $sender,
        private PacketBroadcaster $broadcaster,
        private EntityEventBroadcaster $entityEventBroadcaster,
        private Compressor $compressor,
        private TypeConverter $typeConverter,
        private string $ip,
        private int $port
    ){
        $this->logger = new \PrefixedLogger($this->server->getLogger(), $this->getLogPrefix());

        $this->compressedQueue = new \SplQueue();

        $this->disposeHooks = new ObjectSet();

        $this->connectTime = time();
        $this->packetBatchLimiter = new PacketRateLimiter("Packet Batches", self::INCOMING_PACKET_BATCH_PER_TICK, self::INCOMING_PACKET_BATCH_BUFFER_TICKS);
        $this->gamePacketLimiter = new PacketRateLimiter("Game Packets", self::INCOMING_GAME_PACKETS_PER_TICK, self::INCOMING_GAME_PACKETS_BUFFER_TICKS);

        $this->setHandler(new SessionStartPacketHandler(
            $this,
            $this->onSessionStartSuccess(...)
        ));

        $this->manager->add($this);
        $this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_open()));
    }

    private function getLogPrefix() : string{
        return "NetworkSession: " . $this->getDisplayName();
    }

    public function getLogger() : \Logger{
        return $this->logger;
    }

    private function onSessionStartSuccess() : void{
        $this->logger->debug("Session start handshake completed, awaiting login packet");
        $this->flushGamePacketQueue();
        $this->enableCompression = true;
        $this->setHandler(new LoginPacketHandler(
            $this->server,
            $this,
            function(PlayerInfo $info) : void{
                $this->info = $info;
                $this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_playerName(TextFormat::AQUA . $info->getUsername() . TextFormat::RESET)));
                $this->logger->setPrefix($this->getLogPrefix());
                $this->manager->markLoginReceived($this);
            },
            $this->setAuthenticationStatus(...)
        ));
    }

    protected function createPlayer() : void{
        $this->server->createPlayer($this, $this->info, $this->authenticated, $this->cachedOfflinePlayerData)->onCompletion(
            $this->onPlayerCreated(...),
            function() : void{
                //TODO: this should never actually occur... right?
                $this->disconnectWithError(
                    reason: "Failed to create player",
                    disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_internal()
                );
            }
        );
    }

    private function onPlayerCreated(Player $player) : void{
        if(!$this->isConnected()){
            //the remote player might have disconnected before spawn terrain generation was finished
            return;
        }
        $this->player = $player;
        if(!$this->server->addOnlinePlayer($player)){
            return;
        }

        $this->invManager = new InventoryManager($this->player, $this);

        $effectManager = $this->player->getEffects();
        $effectManager->getEffectAddHooks()->add($effectAddHook = function(EffectInstance $effect, bool $replacesOldEffect) : void{
            $this->entityEventBroadcaster->onEntityEffectAdded([$this], $this->player, $effect, $replacesOldEffect);
        });
        $effectManager->getEffectRemoveHooks()->add($effectRemoveHook = function(EffectInstance $effect) : void{
            $this->entityEventBroadcaster->onEntityEffectRemoved([$this], $this->player, $effect);
        });
        $this->disposeHooks->add(static function() use ($effectManager, $effectAddHook, $effectRemoveHook) : void{
            $effectManager->getEffectAddHooks()->remove($effectAddHook);
            $effectManager->getEffectRemoveHooks()->remove($effectRemoveHook);
        });

        $permissionHooks = $this->player->getPermissionRecalculationCallbacks();
        $permissionHooks->add($permHook = function() : void{
            $this->logger->debug("Syncing available commands and abilities/permissions due to permission recalculation");
            $this->syncAbilities($this->player);
            $this->syncAvailableCommands();
        });
        $this->disposeHooks->add(static function() use ($permissionHooks, $permHook) : void{
            $permissionHooks->remove($permHook);
        });
        $this->beginSpawnSequence();
    }

    public function getPlayer() : ?Player{
        return $this->player;
    }

    public function getPlayerInfo() : ?PlayerInfo{
        return $this->info;
    }

    public function isConnected() : bool{
        return $this->connected && !$this->disconnectGuard;
    }

    public function getIp() : string{
        return $this->ip;
    }

    public function getPort() : int{
        return $this->port;
    }

    public function getDisplayName() : string{
        return $this->info !== null ? $this->info->getUsername() : $this->ip . " " . $this->port;
    }

    /** Rest of file unchanged... */

    public function disconnectIncompatibleProtocol(int $protocolVersion) : void{
        $serverProtocol = ProtocolInfo::CURRENT_PROTOCOL;

        // Log the client vs server protocol to make protocol mismatch debugging explicit
        $this->logger->warning("Client attempted to connect with incompatible protocol version: client={$protocolVersion} server={$serverProtocol}");

        $this->tryDisconnect(
            function() use ($protocolVersion, $serverProtocol) : void{
                $playStatus = $protocolVersion < $serverProtocol
                    ? PlayStatusPacket::LOGIN_FAILED_CLIENT
                    : PlayStatusPacket::LOGIN_FAILED_SERVER;

                // Send the PlayStatus packet first (standard behavior)
                $this->sendDataPacket(PlayStatusPacket::create($playStatus), true);

                // Also send a DisconnectPacket with explicit details to improve client-side diagnostic message
                // Some clients may display this message in their disconnect UI; this is best-effort and non-destructive.
                $detailMessage = "Incompatible protocol: client={$protocolVersion} server={$serverProtocol}. Please update your server or client to matching versions.";
                $this->sendDisconnectPacket($detailMessage);
            },
            KnownTranslationFactory::pocketmine_disconnect_incompatibleProtocol((string) $protocolVersion)
        );
    }

}
