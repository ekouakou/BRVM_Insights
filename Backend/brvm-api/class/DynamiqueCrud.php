<?php
class DynamiqueCrud extends DbConnect {
    
    /**
     * Cache pour stocker les colonnes des tables
     * @var array
     */
    private static $tableColumnsCache = [];

    /**
     * Prépare et exécute une requête SQL
     * 
     * @param string $query Requête SQL à exécuter
     * @param array $params Paramètres pour la requête préparée
     * @return PDOStatement|false Retourne le statement ou false en cas d'échec
     */
    protected function executeQuery($query, $params = []) {
        try {
            $statement = $this->pdo->prepare($query);
            $statement->execute($params);
            return $statement;
        } catch (PDOException $e) {
            // Gestion des erreurs - vous pouvez personnaliser selon vos besoins
            error_log("Erreur SQL: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère les colonnes d'une table avec mise en cache
     * 
     * @param string $table Nom de la table
     * @return array Liste des colonnes de la table
     */
    private function getTableColumns($table) {
        $table = $this->sanitizeTableName($table);
        
        // Vérifier le cache d'abord
        if (isset(self::$tableColumnsCache[$table])) {
            return self::$tableColumnsCache[$table];
        }
        
        try {
            // Utiliser DESCRIBE pour obtenir la structure de la table
            $query = "DESCRIBE `$table`";
            $statement = $this->executeQuery($query);
            
            if ($statement === false) {
                error_log("Impossible de récupérer les colonnes de la table: $table");
                return [];
            }
            
            $columns = [];
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            
            // Mettre en cache
            self::$tableColumnsCache[$table] = $columns;
            
            return $columns;
            
        } catch (PDOException $e) {
            error_log("Erreur lors de la récupération des colonnes de $table: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Filtre les paramètres pour ne garder que les colonnes existantes
     * 
     * @param string $table Nom de la table
     * @param array $params Paramètres à filtrer
     * @param bool $logIgnored Si true, log les colonnes ignorées
     * @return array Paramètres filtrés
     */
    private function filterTableParams($table, $params, $logIgnored = true) {
        if (empty($params)) {
            return [];
        }
        
        $tableColumns = $this->getTableColumns($table);
        
        if (empty($tableColumns)) {
            error_log("Aucune colonne trouvée pour la table: $table");
            return [];
        }
        
        $filteredParams = [];
        $ignoredColumns = [];
        
        foreach ($params as $column => $value) {
            if (in_array($column, $tableColumns)) {
                $filteredParams[$column] = $value;
            } else {
                $ignoredColumns[] = $column;
            }
        }
        
        // Log des colonnes ignorées si demandé
        if ($logIgnored && !empty($ignoredColumns)) {
            error_log("Colonnes ignorées pour la table '$table': " . implode(', ', $ignoredColumns));
        }
        
        return $filteredParams;
    }

    /**
     * Vérifier si une table existe
     * 
     * @param string $table Nom de la table
     * @return bool
     */
    private function tableExists($table) {
        try {
            $query = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            error_log("Erreur : " . $e->getMessage());
            return false;
        }
    }


    /**
     * Vide une table dans la base de données
     * 
     * @param string $table Nom de la table à vider
     * @return bool Succès de l'opération
     */
    public function emptyTable($table) {
        // Vérification du nom de table pour éviter les injections SQL
        $table = $this->sanitizeTableName($table);
        
        if (!$this->tableExists($table)) {
            error_log("La table '$table' n'existe pas");
            return false;
        }
        
        $query = "TRUNCATE TABLE `$table`";
        return $this->executeQuery($query) !== false;
    }

    /**
     * Assainit le nom d'une table pour éviter les injections SQL
     * 
     * @param string $tableName Nom de la table
     * @return string Nom de table nettoyé
     */
    private function sanitizeTableName($tableName) {
        // Supprime les caractères dangereux du nom de la table
        return preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    }

    /**
     * Persiste des données dans la base de données de manière sécurisée
     * 
     * @param string $table Nom de la table
     * @param array $params Tableau associatif des données à insérer
     * @param bool $filterColumns Si true, filtre les colonnes inexistantes
     * @return bool|int Succès de l'opération ou ID inséré
     */
    public function persist($table, $params, $filterColumns = true) {
        if (empty($params)) {
            return false;
        }

        $table = $this->sanitizeTableName($table);
        
        // Vérifier que la table existe
        if (!$this->tableExists($table)) {
            error_log("La table '$table' n'existe pas");
            return false;
        }
        
        // Filtrer les paramètres si demandé
        if ($filterColumns) {
            $params = $this->filterTableParams($table, $params);
            
            if (empty($params)) {
                error_log("Aucune colonne valide trouvée après filtrage pour la table '$table'");
                return false;
            }
        }
        
        $fields = array_keys($params);
        $placeholders = array_fill(0, count($fields), '?');
        
        // Échapper les noms de champs
        $escapedFields = array_map(function($field) {
            return "`$field`";
        }, $fields);
        
        $query = "INSERT INTO `$table` (" . implode(', ', $escapedFields) . ") 
                  VALUES (" . implode(', ', $placeholders) . ")";
        
        $statement = $this->executeQuery($query, array_values($params));
        
        if ($statement !== false) {
            // Retourne l'ID de la dernière insertion si disponible
            $lastId = $this->pdo->lastInsertId();
            return $lastId ? $lastId : true;
        }
        
        return $this->tableExists($table);
    }

    /**
 * Persiste des données dans la base de données de manière sécurisée
 * 
 * @param string $table Nom de la table
 * @param array $params Tableau associatif des données à insérer
 * @param bool $filterColumns Si true, filtre les colonnes inexistantes
 * @return bool|int Succès de l'opération ou ID inséré
 */
public function persist__($table, $params, $filterColumns = true) {
    if (empty($params)) {
        error_log("CRUD persist(): Paramètres vides pour la table '$table'");
        return false;
    }

    $table = $this->sanitizeTableName($table);
    
    // Vérifier que la table existe
    if (!$this->tableExists($table)) {
        error_log("CRUD persist(): La table '$table' n'existe pas");
        return false;
    }
    
    // Filtrer les paramètres si demandé
    if ($filterColumns) {
        $originalCount = count($params);
        $params = $this->filterTableParams($table, $params);
        
        if (empty($params)) {
            error_log("CRUD persist(): Aucune colonne valide trouvée après filtrage pour la table '$table'");
            return false;
        }
        
        error_log("CRUD persist(): Table '$table' - Colonnes avant filtrage: $originalCount, après filtrage: " . count($params));
    }
    
    $fields = array_keys($params);
    $placeholders = array_fill(0, count($fields), '?');
    
    // Échapper les noms de champs
    $escapedFields = array_map(function($field) {
        return "`$field`";
    }, $fields);
    
    $query = "INSERT INTO `$table` (" . implode(', ', $escapedFields) . ") 
              VALUES (" . implode(', ', $placeholders) . ")";
    
    error_log("CRUD persist(): Exécution requête pour table '$table': $query");
    error_log("CRUD persist(): Paramètres: " . json_encode(array_values($params)));
    
    $statement = $this->executeQuery($query, array_values($params));
    
    if ($statement !== false) {
        // Retourne l'ID de la dernière insertion si disponible
        $lastId = $this->pdo->lastInsertId();
        if ($lastId && $lastId !== '0') {
            error_log("CRUD persist(): Insertion réussie dans '$table', ID retourné: $lastId");
            return (int)$lastId;
        } else {
            error_log("CRUD persist(): Insertion réussie dans '$table' mais pas d'ID retourné (table sans auto-increment ?)");
            return true;
        }
    }
    
    error_log("CRUD persist(): Échec de l'insertion dans la table '$table'");
    return false;
}

    /**
     * Met à jour des données dans la base de données de manière sécurisée
     * 
     * @param string $table Nom de la table
     * @param array $params_to_update Données à mettre à jour
     * @param array $params_condition Conditions de la mise à jour
     * @param bool $filterColumns Si true, filtre les colonnes inexistantes
     * @return bool|int Succès de l'opération ou nombre de lignes affectées
     */
    public function merge($table, $params_to_update, $params_condition, $filterColumns = true) {
        if (empty($params_to_update)) {
            return false;
        }

        $table = $this->sanitizeTableName($table);
        
        // Vérifier que la table existe
        if (!$this->tableExists($table)) {
            error_log("La table '$table' n'existe pas");
            return false;
        }
        
        // Filtrer les paramètres si demandé
        if ($filterColumns) {
            $params_to_update = $this->filterTableParams($table, $params_to_update);
            $params_condition = $this->filterTableParams($table, $params_condition, false); // Pas de log pour conditions
            
            if (empty($params_to_update)) {
                error_log("Aucune colonne valide à mettre à jour pour la table '$table'");
                return false;
            }
        }
        
        $updateFields = [];
        $allParams = [];
        
        // Construction des champs à mettre à jour avec paramètres nommés
        foreach ($params_to_update as $field => $value) {
            $updateFields[] = "`$field` = ?";
            $allParams[] = $value;
        }
        
        $query = "UPDATE `$table` SET " . implode(', ', $updateFields);
        
        // Ajout des conditions
        if (!empty($params_condition)) {
            $whereConditions = [];
            foreach ($params_condition as $field => $value) {
                $whereConditions[] = "`$field` = ?";
                $allParams[] = $value;
            }
            $query .= " WHERE " . implode(' AND ', $whereConditions);
        }
        
        $statement = $this->executeQuery($query, $allParams);
        
        if ($statement !== false) {
            return $statement->rowCount();
        }
        
        return false;
    }

    /**
     * Supprime un enregistrement de la base de données
     * 
     * @param string $table Nom de la table
     * @param array $params Conditions de suppression
     * @param bool $filterColumns Si true, filtre les colonnes inexistantes
     * @return bool|int Succès de l'opération ou nombre de lignes affectées
     */
    public function remove($table, $params, $filterColumns = true) {
        if (empty($params)) {
            return false;
        }

        $table = $this->sanitizeTableName($table);
        
        // Vérifier que la table existe
        if (!$this->tableExists($table)) {
            error_log("La table '$table' n'existe pas");
            return false;
        }
        
        // Filtrer les paramètres si demandé
        if ($filterColumns) {
            $params = $this->filterTableParams($table, $params, false);
            
            if (empty($params)) {
                error_log("Aucune colonne valide pour les conditions de suppression dans la table '$table'");
                return false;
            }
        }
        
        // Vérifier si l'enregistrement existe avant de le supprimer
        if (!$this->exists($table, $params)) {
            return false;
        }
        
        $whereConditions = [];
        $values = [];
        
        foreach ($params as $field => $value) {
            $whereConditions[] = "`$field` = ?";
            $values[] = $value;
        }
        
        $query = "DELETE FROM `$table` WHERE " . implode(' AND ', $whereConditions) . " LIMIT 1";
        $statement = $this->executeQuery($query, $values);
        
        if ($statement !== false) {
            return $statement->rowCount();
        }
        
        return false;
    }

    /**
     * Vérifie si des données existent dans la table
     * 
     * @param string $table Nom de la table
     * @param array $params Conditions de recherche
     * @param bool $filterColumns Si true, filtre les colonnes inexistantes
     * @return bool Existence des données
     */
    public function exists($table, $params, $filterColumns = true) {
        return $this->count($table, $params, $filterColumns) > 0;
    }

    /**
     * Compte le nombre d'enregistrements correspondant aux critères
     * 
     * @param string $table Nom de la table
     * @param array $params Conditions de recherche
     * @param bool $filterColumns Si true, filtre les colonnes inexistantes
     * @return int Nombre d'enregistrements
     */
    public function count($table, $params = [], $filterColumns = true) {
        $table = $this->sanitizeTableName($table);
        
        // Vérifier que la table existe
        if (!$this->tableExists($table)) {
            error_log("La table '$table' n'existe pas");
            return 0;
        }
        
        $query = "SELECT COUNT(*) FROM `$table`";
        $values = [];
        
        if (!empty($params)) {
            // Filtrer les paramètres si demandé
            if ($filterColumns) {
                $params = $this->filterTableParams($table, $params, false);
            }
            
            if (!empty($params)) {
                $whereConditions = [];
                foreach ($params as $field => $value) {
                    $whereConditions[] = "`$field` = ?";
                    $values[] = $value;
                }
                $query .= " WHERE " . implode(' AND ', $whereConditions);
            }
        }
        
        $statement = $this->executeQuery($query, $values);
        
        return ($statement !== false) ? (int)$statement->fetchColumn() : 0;
    }

    /**
     * Recherche un élément dans la base de données
     * 
     * @param string $table Nom de la table
     * @param array $params Conditions de recherche
     * @param string $fetchMode Mode de récupération (assoc, object, etc.)
     * @param bool $filterColumns Si true, filtre les colonnes inexistantes
     * @return array|object|null Résultat de la recherche
     */
    public function find($table, $params, $fetchMode = PDO::FETCH_ASSOC, $filterColumns = true, $orderBy = '') {
    $table = $this->sanitizeTableName($table);

    // Vérifier que la table existe
    if (!$this->tableExists($table)) {
        error_log("La table '$table' n'existe pas");
        return [];
    }

    // Filtrer les paramètres si demandé
    if ($filterColumns) {
        $params = $this->filterTableParams($table, $params, false);
    }

    if (empty($params)) {
        error_log("Aucune condition valide pour la recherche dans la table '$table'");
        return [];
    }

    $whereConditions = [];
    $values = [];

    foreach ($params as $field => $value) {
        $whereConditions[] = "`$field` = ?";
        $values[] = $value;
    }

    $query = "SELECT * FROM `$table` WHERE " . implode(' AND ', $whereConditions);

    // Ajouter ORDER BY si fourni
    if (!empty($orderBy)) {
        $query .= " ORDER BY $orderBy";
    }

    $statement = $this->executeQuery($query, $values);

    if ($statement !== false) {
        return $statement->fetchAll($fetchMode);
    }

    return [];
}


    /**
     * Recherche tous les éléments d'une table
     * 
     * @param string $table Nom de la table
     * @param string $orderBy Champ de tri
     * @param string $orderDirection Direction du tri (ASC/DESC)
     * @param int $limit Limite de résultats
     * @param int $offset Décalage pour la pagination
     * @param string $fetchMode Mode de récupération
     * @return array Résultats de la recherche
     */
    public function findAll($table, $orderBy = 'id', $orderDirection = 'ASC', $limit = null, $offset = null, $fetchMode = PDO::FETCH_ASSOC) {
        $table = $this->sanitizeTableName($table);
        
        // Vérifier que la table existe
        if (!$this->tableExists($table)) {
            error_log("La table '$table' n'existe pas");
            return [];
        }
        
        // Vérifier que la colonne orderBy existe
        $tableColumns = $this->getTableColumns($table);
        if (!in_array($orderBy, $tableColumns)) {
            error_log("La colonne '$orderBy' n'existe pas dans la table '$table', utilisation de 'id' par défaut");
            $orderBy = in_array('id', $tableColumns) ? 'id' : $tableColumns[0];
        }
        
        $orderDirection = strtoupper($orderDirection) === 'DESC' ? 'DESC' : 'ASC';
        
        $query = "SELECT * FROM `$table` ORDER BY `$orderBy` $orderDirection";
        
        // Ajout de la pagination si nécessaire
        if ($limit !== null) {
            $query .= " LIMIT " . (int)$limit;
            
            if ($offset !== null) {
                $query .= " OFFSET " . (int)$offset;
            }
        }
        
        $statement = $this->executeQuery($query);
        
        if ($statement !== false) {
            return $statement->fetchAll($fetchMode);
        }
        
        return [];
    }

    /**
     * Effectue une requête avec jointure
     * 
     * @param array $tables Tableaux des tables à joindre
     * @param array $joins Configuration des jointures
     * @param array $fields Champs à sélectionner
     * @param array $where Conditions WHERE
     * @param array $orderBy Tri des résultats
     * @param int $limit Limite de résultats
     * @param int $offset Décalage pour la pagination
     * @return array Résultats de la requête
     */
    public function findWithJoin(array $tables, array $joins, array $fields = ['*'], array $where = [], array $orderBy = [], $limit = null, $offset = null) {
        if (empty($tables)) {
            return [];
        }
        
        // Assainir le nom des tables
        $sanitizedTables = [];
        foreach ($tables as $alias => $table) {
            $sanitizedTable = $this->sanitizeTableName($table);
            
            // Vérifier que la table existe
            if (!$this->tableExists($sanitizedTable)) {
                error_log("La table '$sanitizedTable' n'existe pas");
                return [];
            }
            
            if (is_string($alias)) {
                $sanitizedTables[$alias] = $sanitizedTable;
            } else {
                $sanitizedTables[] = $sanitizedTable;
            }
        }
        
        // Construction des champs à sélectionner
        $selectFields = [];
        foreach ($fields as $alias => $field) {
            if (is_string($alias)) {
                $selectFields[] = "$field AS $alias";
            } else {
                $selectFields[] = $field;
            }
        }
        
        // Construction de la requête de base
        $mainTable = reset($sanitizedTables);
        $mainAlias = key($sanitizedTables);
        
        if (is_string($mainAlias)) {
            $query = "SELECT " . implode(', ', $selectFields) . " FROM `$mainTable` AS `$mainAlias`";
        } else {
            $query = "SELECT " . implode(', ', $selectFields) . " FROM `$mainTable`";
        }
        
        // Construction des jointures
        $params = [];
        foreach ($joins as $join) {
            if (isset($join['type'], $join['table'], $join['on'])) {
                $joinType = strtoupper($join['type']);
                $joinTable = $this->sanitizeTableName($join['table']);
                
                // Vérifier que la table de jointure existe
                if (!$this->tableExists($joinTable)) {
                    error_log("La table de jointure '$joinTable' n'existe pas");
                    return [];
                }
                
                $joinAlias = isset($join['alias']) ? $join['alias'] : $joinTable;
                
                if (!in_array($joinType, ['INNER', 'LEFT', 'RIGHT', 'FULL'])) {
                    $joinType = 'INNER';
                }
                
                $query .= " $joinType JOIN `$joinTable` AS `$joinAlias` ON " . $join['on'];
                
                // Ajout des paramètres pour la condition ON si nécessaire
                if (isset($join['params']) && is_array($join['params'])) {
                    $params = array_merge($params, $join['params']);
                }
            }
        }
        
        // Ajout des conditions WHERE
        if (!empty($where)) {
            $whereConditions = [];
            foreach ($where as $condition => $value) {
                if (is_int($condition)) {
                    // C'est une condition brute
                    $whereConditions[] = $value;
                } else {
                    // C'est une condition avec paramètre
                    $whereConditions[] = $condition;
                    $params[] = $value;
                }
            }
            $query .= " WHERE " . implode(' AND ', $whereConditions);
        }
        
        // Ajout du tri
        if (!empty($orderBy)) {
            $orders = [];
            foreach ($orderBy as $field => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $orders[] = "$field $direction";
            }
            $query .= " ORDER BY " . implode(', ', $orders);
        }
        
        // Ajout de la pagination
        if ($limit !== null) {
            $query .= " LIMIT " . (int)$limit;
            
            if ($offset !== null) {
                $query .= " OFFSET " . (int)$offset;
            }
        }
        
        $statement = $this->executeQuery($query, $params);
        
        if ($statement !== false) {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return [];
    }

    /**
     * Exécute une requête SQL personnalisée
     * 
     * @param string $query Requête SQL
     * @param array $params Paramètres pour la requête préparée
     * @param string $fetchMode Mode de récupération
     * @return array|bool Résultats de la requête ou état de succès
     */
    public function executeCustomQuery($query, $params = [], $fetchMode = PDO::FETCH_ASSOC) {
        $statement = $this->executeQuery($query, $params);
        
        if ($statement !== false) {
            if (stripos(trim($query), 'SELECT') === 0) {
                return $statement->fetchAll($fetchMode);
            }
            return $statement->rowCount();
        }
        
        return false;
    }

    /**
     * Effectue une transaction SQL
     * 
     * @param callable $callback Fonction à exécuter dans la transaction
     * @return mixed Résultat de la fonction ou false en cas d'échec
     */
    public function transaction(callable $callback) {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Transaction error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Pagination des résultats
     * 
     * @param string $table Nom de la table
     * @param int $page Numéro de page
     * @param int $perPage Nombre d'éléments par page
     * @param array $where Conditions de recherche
     * @param array $orderBy Tri des résultats
     * @param bool $filterColumns Si true, filtre les colonnes inexistantes
     * @return array Données paginées et métadonnées
     */
    public function paginate($table, $page = 1, $perPage = 10, array $where = [], array $orderBy = ['id' => 'ASC'], $filterColumns = true) {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;
        
        $table = $this->sanitizeTableName($table);
        
        // Vérifier que la table existe
        if (!$this->tableExists($table)) {
            error_log("La table '$table' n'existe pas");
            return [
                'data' => [],
                'meta' => [
                    'current_page' => $page,
                    'last_page' => 0,
                    'per_page' => $perPage,
                    'total' => 0,
                    'from' => 0,
                    'to' => 0
                ]
            ];
        }
        
        // Filtrer les conditions WHERE si demandé
        if ($filterColumns && !empty($where)) {
            $where = $this->filterTableParams($table, $where, false);
        }
        
        // Filtrer et valider les champs de tri
        $tableColumns = $this->getTableColumns($table);
        $validOrderBy = [];
        foreach ($orderBy as $field => $direction) {
            if (in_array($field, $tableColumns)) {
                $validOrderBy[$field] = $direction;
            } else {
                error_log("Colonne de tri '$field' ignorée car inexistante dans la table '$table'");
            }
        }
        
        // Si aucun tri valide, utiliser un tri par défaut
        if (empty($validOrderBy)) {
            if (in_array('id', $tableColumns)) {
                $validOrderBy = ['id' => 'ASC'];
            } else {
                // Utiliser la première colonne disponible
                $validOrderBy = [$tableColumns[0] => 'ASC'];
            }
        }
        
        // Compter le nombre total d'enregistrements
        $total = $this->count($table, $where, false); // Pas de filtrage car déjà fait
        
        // Récupérer les données paginées
        $data = [];
        if ($total > 0) {
            $query = "SELECT * FROM `$table`";
            $params = [];
            
            // Ajout des conditions WHERE
            if (!empty($where)) {
                $whereConditions = [];
                foreach ($where as $field => $value) {
                    $whereConditions[] = "`$field` = ?";
                    $params[] = $value;
                }
                $query .= " WHERE " . implode(' AND ', $whereConditions);
            }
            
            // Ajout du tri
            if (!empty($validOrderBy)) {
                $orders = [];
                foreach ($validOrderBy as $field => $direction) {
                    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                    $orders[] = "`$field` $direction";
                }
                $query .= " ORDER BY " . implode(', ', $orders);
            }
            
            $query .= " LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = $offset;
            
            $statement = $this->executeQuery($query, $params);
            
            if ($statement !== false) {
                $data = $statement->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        // Calculer les métadonnées de pagination
        $lastPage = ceil($total / $perPage);
        
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total)
            ]
        ];
    }

    /**
     * Vide le cache des colonnes des tables
     * Utile après modification de structure de table
     */
    public function clearTableColumnsCache() {
        self::$tableColumnsCache = [];
    }

    /**
     * Retourne les colonnes d'une table (méthode publique)
     * 
     * @param string $table Nom de la table
     * @return array Liste des colonnes
     */
    public function getColumnsForTable($table) {
        return $this->getTableColumns($table);
    }

    /**
     * Effectue une requête avec jointure et pagination
     * 
     * @param array $tables Tableaux des tables à joindre
     * @param array $joins Configuration des jointures
     * @param array $fields Champs à sélectionner
     * @param array $where Conditions WHERE
     * @param array $orderBy Tri des résultats
     * @param int $limit Limite de résultats
     * @param int $offset Décalage pour la pagination
     * @return array Résultats de la requête paginés
     */
    public function findWithJoinPaginated(array $tables, array $joins, array $fields = ['*'], array $where = [], array $orderBy = [], $limit = null, $offset = null) {
        if (empty($tables)) {
            return [];
        }
        
        // Assainir le nom des tables
        $sanitizedTables = [];
        foreach ($tables as $alias => $table) {
            $sanitizedTable = $this->sanitizeTableName($table);
            
            // Vérifier que la table existe
            if (!$this->tableExists($sanitizedTable)) {
                error_log("La table '$sanitizedTable' n'existe pas");
                return [];
            }
            
            if (is_string($alias)) {
                $sanitizedTables[$alias] = $sanitizedTable;
            } else {
                $sanitizedTables[] = $sanitizedTable;
            }
        }
        
        // Construction des champs à sélectionner
        $selectFields = [];
        foreach ($fields as $alias => $field) {
            if (is_string($alias)) {
                $selectFields[] = "$field AS `$alias`";
            } else {
                $selectFields[] = $field;
            }
        }
        
        // Construction de la requête de base
        $mainTable = reset($sanitizedTables);
        $mainAlias = key($sanitizedTables);
        
        if (is_string($mainAlias)) {
            $query = "SELECT " . implode(', ', $selectFields) . " FROM `$mainTable` AS `$mainAlias`";
        } else {
            $query = "SELECT " . implode(', ', $selectFields) . " FROM `$mainTable`";
        }
        
        // Construction des jointures
        $params = [];
        foreach ($joins as $join) {
            if (isset($join['type'], $join['table'], $join['on'])) {
                $joinType = strtoupper($join['type']);
                $joinTable = $this->sanitizeTableName($join['table']);
                
                // Vérifier que la table de jointure existe
                if (!$this->tableExists($joinTable)) {
                    error_log("La table de jointure '$joinTable' n'existe pas");
                    return [];
                }
                
                $joinAlias = isset($join['alias']) ? $join['alias'] : $joinTable;
                
                if (!in_array($joinType, ['INNER', 'LEFT', 'RIGHT', 'FULL'])) {
                    $joinType = 'INNER';
                }
                
                $query .= " $joinType JOIN `$joinTable` AS `$joinAlias` ON " . $join['on'];
                
                // Ajout des paramètres pour la condition ON si nécessaire
                if (isset($join['params']) && is_array($join['params'])) {
                    $params = array_merge($params, $join['params']);
                }
            }
        }
        
        // Ajout des conditions WHERE
        if (!empty($where)) {
            $whereConditions = [];
            foreach ($where as $condition => $value) {
                if (is_int($condition)) {
                    // C'est une condition brute
                    $whereConditions[] = $value;
                } else {
                    // C'est une condition avec paramètre
                    $whereConditions[] = $condition;
                    $params[] = $value;
                }
            }
            $query .= " WHERE " . implode(' AND ', $whereConditions);
        }
        
        // Ajout du tri
        if (!empty($orderBy)) {
            $orders = [];
            foreach ($orderBy as $field => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $orders[] = "$field $direction";
            }
            $query .= " ORDER BY " . implode(', ', $orders);
        }
        
        // Ajout de la pagination
        if ($limit !== null) {
            $query .= " LIMIT " . (int)$limit;
            
            if ($offset !== null) {
                $query .= " OFFSET " . (int)$offset;
            }
        }
        
        $statement = $this->executeQuery($query, $params);
        
        if ($statement !== false) {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return [];
    }

    /**
     * Compte le nombre d'enregistrements avec jointures
     * 
     * @param array $tables Tableaux des tables à joindre
     * @param array $joins Configuration des jointures
     * @param array $where Conditions WHERE
     * @return int Nombre d'enregistrements
     */
    public function countWithJoin(array $tables, array $joins, array $where = []) {
        if (empty($tables)) {
            return 0;
        }
        
        // Assainir le nom des tables
        $sanitizedTables = [];
        foreach ($tables as $alias => $table) {
            $sanitizedTable = $this->sanitizeTableName($table);
            
            // Vérifier que la table existe
            if (!$this->tableExists($sanitizedTable)) {
                error_log("La table '$sanitizedTable' n'existe pas");
                return 0;
            }
            
            if (is_string($alias)) {
                $sanitizedTables[$alias] = $sanitizedTable;
            } else {
                $sanitizedTables[] = $sanitizedTable;
            }
        }
        
        // Construction de la requête de base
        $mainTable = reset($sanitizedTables);
        $mainAlias = key($sanitizedTables);
        
        if (is_string($mainAlias)) {
            $query = "SELECT COUNT(*) FROM `$mainTable` AS `$mainAlias`";
        } else {
            $query = "SELECT COUNT(*) FROM `$mainTable`";
        }
        
        // Construction des jointures
        $params = [];
        foreach ($joins as $join) {
            if (isset($join['type'], $join['table'], $join['on'])) {
                $joinType = strtoupper($join['type']);
                $joinTable = $this->sanitizeTableName($join['table']);
                
                // Vérifier que la table de jointure existe
                if (!$this->tableExists($joinTable)) {
                    error_log("La table de jointure '$joinTable' n'existe pas");
                    return 0;
                }
                
                $joinAlias = isset($join['alias']) ? $join['alias'] : $joinTable;
                
                if (!in_array($joinType, ['INNER', 'LEFT', 'RIGHT', 'FULL'])) {
                    $joinType = 'INNER';
                }
                
                $query .= " $joinType JOIN `$joinTable` AS `$joinAlias` ON " . $join['on'];
                
                // Ajout des paramètres pour la condition ON si nécessaire
                if (isset($join['params']) && is_array($join['params'])) {
                    $params = array_merge($params, $join['params']);
                }
            }
        }
        
        // Ajout des conditions WHERE
        if (!empty($where)) {
            $whereConditions = [];
            foreach ($where as $condition => $value) {
                if (is_int($condition)) {
                    // C'est une condition brute
                    $whereConditions[] = $value;
                } else {
                    // C'est une condition avec paramètre
                    $whereConditions[] = $condition;
                    $params[] = $value;
                }
            }
            $query .= " WHERE " . implode(' AND ', $whereConditions);
        }
        
        $statement = $this->executeQuery($query, $params);
        
        return ($statement !== false) ? (int)$statement->fetchColumn() : 0;
    }

    /**
     * Trouve un enregistrement par son ID
     * 
     * @param string $table Nom de la table
     * @param int $id ID de l'enregistrement
     * @param string $fetchMode Mode de récupération
     * @return array|null Enregistrement trouvé ou null
     */
    public function findById($table, $id, $fetchMode = PDO::FETCH_ASSOC) {
        $table = $this->sanitizeTableName($table);
        
        // Vérifier que la table existe
        if (!$this->tableExists($table)) {
            error_log("La table '$table' n'existe pas");
            return null;
        }
        
        // Vérifier que la colonne 'id' existe
        $tableColumns = $this->getTableColumns($table);
        if (!in_array('id', $tableColumns)) {
            error_log("La colonne 'id' n'existe pas dans la table '$table'");
            return null;
        }
        
        $query = "SELECT * FROM `$table` WHERE `id` = ? LIMIT 1";
        $statement = $this->executeQuery($query, [(int)$id]);
        
        if ($statement !== false) {
            $result = $statement->fetch($fetchMode);
            return $result ?: null;
        }
        
        return null;
    }

    /**
     * Retourne la dernière erreur de base de données
     * 
     * @return array|null Informations sur la dernière erreur
     */
    public function getLastError() {
        if ($this->pdo) {
            $errorInfo = $this->pdo->errorInfo();
            if ($errorInfo[0] !== '00000') {
                return [
                    'sqlstate' => $errorInfo[0],
                    'code' => $errorInfo[1],
                    'message' => $errorInfo[2]
                ];
            }
        }
        return null;
    }
}
?>