<?php

require_once __DIR__ . '/../Database.php';

/**
 * Minimal active-record-ish base class.
 *
 * Deliberately NOT a full ORM: this project prioritizes clarity, explicit
 * SQL for anything non-trivial, and small blast radius per class -- fitting
 * with plain-PHP conventions rather than pulling in Eloquent/Doctrine for a
 * schema this size. Subclasses set $table and $fillable and get find/all/
 * create/update/delete for free; anything more specific (joins, ranking
 * math, timer resolution) lives as explicit methods on the relevant model.
 */
abstract class Model
{
    protected static string $table;

    /** @var string[] Columns that may be mass-assigned via create()/update() */
    protected static array $fillable = [];

    protected static function db(): PDO
    {
        return Database::connection();
    }

    public static function find(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM ' . static::$table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public static function findOrFail(int $id): array
    {
        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException(static::$table . " row {$id} not found");
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $where Simple equality conditions, ANDed together.
     * @param string $orderBy Raw ORDER BY clause (column names only, not user input).
     */
    public static function where(array $where = [], string $orderBy = ''): array
    {
        $sql = 'SELECT * FROM ' . static::$table;
        $params = [];

        if ($where !== []) {
            $clauses = [];
            foreach ($where as $column => $value) {
                $clauses[] = "{$column} = :{$column}";
                $params[$column] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . $orderBy;
        }

        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function all(string $orderBy = ''): array
    {
        return self::where([], $orderBy);
    }

    /**
     * Inserts a row, restricted to keys declared in $fillable, and returns
     * the newly created row.
     */
    public static function create(array $attributes): array
    {
        $data = self::onlyFillable($attributes);

        $columns = array_keys($data);
        $placeholders = array_map(fn ($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            static::$table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = self::db()->prepare($sql);
        $stmt->execute($data);

        return self::findOrFail((int) self::db()->lastInsertId());
    }

    public static function update(int $id, array $attributes): array
    {
        $data = self::onlyFillable($attributes);

        if ($data === []) {
            return self::findOrFail($id);
        }

        $assignments = array_map(fn ($c) => "{$c} = :{$c}", array_keys($data));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE id = :id',
            static::$table,
            implode(', ', $assignments)
        );

        $data['id'] = $id;

        $stmt = self::db()->prepare($sql);
        $stmt->execute($data);

        return self::findOrFail($id);
    }

    public static function delete(int $id): bool
    {
        $stmt = self::db()->prepare('DELETE FROM ' . static::$table . ' WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Sets display_order sequentially (0, 1, 2, ...) for the given ids, in
     * the order they're passed. Used by every "reorder" admin action
     * (categories, contestants, questions) -- callers are responsible for
     * ensuring all ids belong to the same event before calling this.
     *
     * @param int[] $orderedIds
     */
    public static function reorder(array $orderedIds): void
    {
        $db = self::db();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare('UPDATE ' . static::$table . ' SET display_order = :display_order WHERE id = :id');
            foreach (array_values($orderedIds) as $index => $id) {
                $stmt->execute(['display_order' => $index, 'id' => $id]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    protected static function onlyFillable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(static::$fillable));
    }
}
