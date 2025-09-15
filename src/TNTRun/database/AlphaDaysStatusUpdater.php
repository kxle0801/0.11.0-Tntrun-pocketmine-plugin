<?php

namespace TNTRun\database;

use kxleph\kxmysql\KXMySQL;

class AlphaDaysStatusUpdater {

    private KXMySQL $kxMySQL;
    private \mysqli $database;
    private string $tablePrefix;
    private string $serverId;
    private $plugin;

    public function __construct(KXMySQL $kxMySQL, $plugin) {
        $this->kxMySQL = $kxMySQL;
        $this->plugin = $plugin;
        $this->database = $this->kxMySQL->getDatabase();
        $config = $this->kxMySQL->getDatabaseConfig();
        $this->tablePrefix = $config['table_prefix'];
        $this->serverId = $config['server_id'];

        $this->createServerInfoTable();
    }
    
    private function isDatabaseValid(): bool {
        try {
            return $this->database !== null && !$this->database->connect_errno && $this->database->ping();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private function createServerInfoTable(): void {
        if (!$this->isDatabaseValid()) {
            $this->plugin->getLogger()->warning("Database connection is not valid, skipping table creation");
            return;
        }

        $prefix = $this->tablePrefix;

        $serverInfo = "CREATE TABLE IF NOT EXISTS `{$prefix}server_info` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `server_id` VARCHAR(32) NOT NULL,
            `server_name` VARCHAR(64) NOT NULL,
            `server_address` VARCHAR(255) NOT NULL,
            `server_port` INT NOT NULL,
            `game_state` INT DEFAULT 0,
            `current_players` INT DEFAULT 0,
            `max_players` INT DEFAULT 20,
            `is_online` BOOLEAN DEFAULT FALSE,
            `last_update` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_server_entry` (`server_id`, `server_address`, `server_port`),
            INDEX `idx_server_id` (`server_id`),
            INDEX `idx_server_address` (`server_address`),
            INDEX `idx_is_online` (`is_online`),
            INDEX `idx_game_state` (`game_state`),
            INDEX `idx_last_update` (`last_update`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        $this->database->query($serverInfo);

        if ($this->database->error) {
            $this->plugin->getLogger()->error("MySQL Error creating server_info table: " . $this->database->error);
        }
    }

    public function registerServer(string $serverName, string $serverAddress, int $serverPort): bool {
        if (!$this->isDatabaseValid()) {
            $this->plugin->getLogger()->warning("Database connection is not valid, skipping server registration");
            return false;
        }

        $prefix = $this->tablePrefix;

        $stmt = $this->database->prepare("INSERT INTO `{$prefix}server_info` 
            (`server_id`, `server_name`, `server_address`, `server_port`, `is_online`, `last_update`) 
            VALUES (?, ?, ?, ?, TRUE, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE 
            `server_name` = VALUES(`server_name`),
            `is_online` = TRUE,
            `last_update` = CURRENT_TIMESTAMP");
        
        if ($stmt === false) {
            $this->plugin->getLogger()->error("MySQL prepare failed in registerServer: " . $this->database->error);
            return false;
        }

        if (!$stmt->bind_param("sssi", $this->serverId, $serverName, $serverAddress, $serverPort)) {
            $this->plugin->getLogger()->error("MySQL bind_param failed in registerServer: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $result = $stmt->execute();
        if (!$result) {
            $this->plugin->getLogger()->error("MySQL execute failed in registerServer: " . $stmt->error);
        }

        $stmt->close();
        return $result;
    }

    public function updateServerStatus(int $gameState, int $currentPlayers, int $maxPlayers, string $serverAddress, int $serverPort): bool {
        if (!$this->isDatabaseValid()) {
            $this->plugin->getLogger()->debug("Database connection is not valid, skipping status update");
            return false;
        }

        $prefix = $this->tablePrefix;
        
        if (!$this->serverAddressExists($serverAddress, $serverPort)) {
            $config = $this->plugin->getPluginConfig();
            $serverName = $config->get("server-name", "Game Server");
            
            if (!$this->registerServer($serverName, $serverAddress, $serverPort)) {
                return false;
            }
        }

        $stmt = $this->database->prepare("UPDATE `{$prefix}server_info` SET 
            `game_state` = ?,
            `current_players` = ?,
            `max_players` = ?,
            `is_online` = TRUE,
            `last_update` = CURRENT_TIMESTAMP
            WHERE `server_id` = ? AND `server_address` = ? AND `server_port` = ?");
        
        if ($stmt === false) {
            $this->plugin->getLogger()->error("MySQL prepare failed in updateServerStatus: " . $this->database->error);
            return false;
        }

        if (!$stmt->bind_param("iiissi", $gameState, $currentPlayers, $maxPlayers, $this->serverId, $serverAddress, $serverPort)) {
            $this->plugin->getLogger()->error("MySQL bind_param failed in updateServerStatus: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $result = $stmt->execute();
        if (!$result) {
            $this->plugin->getLogger()->error("MySQL execute failed in updateServerStatus: " . $stmt->error);
        }
        
        $stmt->close();
        return $result;
    }

    public function markServerOffline(string $serverAddress, int $serverPort): bool {
        if (!$this->isDatabaseValid()) {
            $this->plugin->getLogger()->warning("Database connection is not valid, skipping marking server offline");
            return false;
        }

        $prefix = $this->tablePrefix;

        $stmt = $this->database->prepare("UPDATE `{$prefix}server_info` SET 
            `is_online` = FALSE,
            `current_players` = 0,
            `last_update` = CURRENT_TIMESTAMP
            WHERE `server_id` = ? AND `server_address` = ? AND `server_port` = ?");
        
        if ($stmt === false) {
            $this->plugin->getLogger()->error("MySQL prepare failed in markServerOffline: " . $this->database->error);
            return false;
        }

        if (!$stmt->bind_param("ssi", $this->serverId, $serverAddress, $serverPort)) {
            $this->plugin->getLogger()->error("MySQL bind_param failed in markServerOffline: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $result = $stmt->execute();
        if (!$result) {
            $this->plugin->getLogger()->error("MySQL execute failed in markServerOffline: " . $stmt->error);
        }

        $stmt->close();
        return $result;
    }
    
    public function markAllServersOffline(): bool {
        if (!$this->isDatabaseValid()) {
            $this->plugin->getLogger()->warning("Database connection is not valid, skipping marking all servers offline");
            return false;
        }

        $prefix = $this->tablePrefix;

        $stmt = $this->database->prepare("UPDATE `{$prefix}server_info` SET 
            `is_online` = FALSE,
            `current_players` = 0,
            `last_update` = CURRENT_TIMESTAMP
            WHERE `server_id` = ?");
        
        if ($stmt === false) {
            $this->plugin->getLogger()->error("MySQL prepare failed in markAllServersOffline: " . $this->database->error);
            return false;
        }

        if (!$stmt->bind_param("s", $this->serverId)) {
            $this->plugin->getLogger()->error("MySQL bind_param failed in markAllServersOffline: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $result = $stmt->execute();
        if (!$result) {
            $this->plugin->getLogger()->error("MySQL execute failed in markAllServersOffline: " . $stmt->error);
        }

        $stmt->close();
        return $result;
    }
    
    private function serverAddressExists(string $serverAddress, int $serverPort): bool {
        if (!$this->isDatabaseValid()) {
            return false;
        }

        $prefix = $this->tablePrefix;

        $stmt = $this->database->prepare("SELECT 1 FROM `{$prefix}server_info` WHERE `server_id` = ? AND `server_address` = ? AND `server_port` = ? LIMIT 1");
        if ($stmt === false) {
            $this->plugin->getLogger()->error("MySQL prepare failed in serverAddressExists: " . $this->database->error);
            return false;
        }

        if (!$stmt->bind_param("ssi", $this->serverId, $serverAddress, $serverPort)) {
            $this->plugin->getLogger()->error("MySQL bind_param failed in serverAddressExists: " . $stmt->error);
            $stmt->close();
            return false;
        }

        if (!$stmt->execute()) {
            $this->plugin->getLogger()->error("MySQL execute failed in serverAddressExists: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        if ($result === false) {
            $this->plugin->getLogger()->error("MySQL get_result failed in serverAddressExists: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    public function getServerAddresses(): array {
        if (!$this->isDatabaseValid()) {
            $this->plugin->getLogger()->warning("Database connection is not valid, returning empty server addresses");
            return [];
        }

        $prefix = $this->tablePrefix;

        $stmt = $this->database->prepare("SELECT `server_address`, `server_port`, `server_name` FROM `{$prefix}server_info` WHERE `server_id` = ?");
        if ($stmt === false) {
            $this->plugin->getLogger()->error("MySQL prepare failed in getServerAddresses: " . $this->database->error);
            return [];
        }

        if (!$stmt->bind_param("s", $this->serverId)) {
            $this->plugin->getLogger()->error("MySQL bind_param failed in getServerAddresses: " . $stmt->error);
            $stmt->close();
            return [];
        }

        if (!$stmt->execute()) {
            $this->plugin->getLogger()->error("MySQL execute failed in getServerAddresses: " . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        if ($result === false) {
            $this->plugin->getLogger()->error("MySQL get_result failed in getServerAddresses: " . $stmt->error);
            $stmt->close();
            return [];
        }

        $addresses = [];
        while ($row = $result->fetch_assoc()) {
            $addresses[] = [
                'address' => $row['server_address'],
                'port' => (int)$row['server_port'],
                'name' => $row['server_name'],
                'full_address' => $row['server_address'] . ':' . $row['server_port']
            ];
        }

        $stmt->close();
        return $addresses;
    }
    
    public function getServerId(): string {
        return $this->serverId;
    }
}