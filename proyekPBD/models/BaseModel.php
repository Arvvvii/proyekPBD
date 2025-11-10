<?php
require_once __DIR__ . '/../config/Database.php';

abstract class BaseModel {
    protected Database $db;
    protected string $table;

    public function __construct(Database $db, string $table) {
        $this->db = $db;
        $this->table = $table;
    }

    // READ harus lewat VIEW
    protected function readView(string $viewName, string $where = '', array $params = []): array {
        $sql = "SELECT * FROM $viewName" . ($where ? " WHERE $where" : '');
        return $this->db->fetchAll($sql, $params);
    }

    public function findById(string $viewName, string $idColumn, $id): ?array {
        $sql = "SELECT * FROM $viewName WHERE $idColumn = ? LIMIT 1";
        return $this->db->fetch($sql, [$id]);
    }

    // CREATE
    public function insert(array $data): int {
        $cols = array_keys($data);
        $place = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO {$this->table} (" . implode(',', $cols) . ") VALUES ($place)";
        $this->db->execute($sql, array_values($data));
        return (int)$this->db->getConnection()->lastInsertId();
    }

    // UPDATE
    public function update($id, array $data, string $idColumn = 'id'): int {
        $sets = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
        $sql = "UPDATE {$this->table} SET $sets WHERE $idColumn = ?";
        $params = array_values($data);
        $params[] = $id;
        return $this->db->execute($sql, $params);
    }

    // DELETE
    public function delete($id, string $idColumn = 'id'): int {
        $sql = "DELETE FROM {$this->table} WHERE $idColumn = ?";
        return $this->db->execute($sql, [$id]);
    }

    // Stored Procedure wrapper
    public function callSP(string $spName, array $inParams = []): void {
        $this->db->callProcedure($spName, $inParams);
    }
}
