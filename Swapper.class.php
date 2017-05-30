<?php

	/*
		Swapper v0.8 
		(c) 2017 by Thielicious

		Simplifies swapping rows in the database back and forth using initial array.
		It creates a UDF and a SP, flexible for any project.

	*/


	if (!is_subclass_of("PDOshort","PDO")) {
	
		abstract class autoBind extends PDO {

			public function prep($sql, array $bind = null) {
				$stmt = $this->prepare($sql);
				$stmt->execute(($bind ? $bind : null));
				return $stmt;
			}

			abstract public function qry($sql);
		}

		final class PDOshort extends autoBind {

			public function qry($sql) {
				return $this->prep($sql);
			}
		}
	}

	trait customPDO {

		private
			$pdo;

		private function connect($host, $db, $user, $pass) {
			$this->host = $host;
			$this->db = $db;
			$this->user = $user;
			$this->pass = $pass;

			try {
				$this->pdo = new PDOshort(
					"mysql:host=".$this->host.";dbname=".$this->db, $this->user, $this->pass,
					array(
						PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
						PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
						PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
						PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'"
					)
				);
			} catch (PDOException $e) {
				exit($e->getMessage());
			}
		}
	}


	class Swapper {
		
		use customPDO;

		public
			$table,	$column, 
			$udf,	$sp,
			$err = [],
			$getRows = [];

		public function __construct(string $tbl, string $col, $host, $db, $user, $pass) {
			$this->table = $tbl;
			$this->column = $col;
			$this->connect($host, $db, $user, $pass);
		}

		public function setUp(string $sp_name, string $udf_name = null) {
			if (count($this->err) == 0) {
				$this->createSP($sp_name);
				$this->createUDF($udf_name);
				$this->assignData();
			} else {
				$this->showErr();
				exit;
			}
		}
		
		private function showErr() {
			if (count($this->err) != 0) {
				if (count($this->err) == 1) {
					echo $this->err."<br>";
				} else {
					foreach ($this->err as $err) {
						echo $err."<br>";
					}
				}
			} 
		}

		private function assignData() {
			try {
				$assign = $this->pdo->qry("
					SELECT ".$this->column." 
						FROM ".$this->table." 
						ORDER BY ".$this->column." ASC
				");
				$column = $this->column;
				foreach ($assign as $row) {
					$this->getRows[] = $row->$column;
				}
			} catch (PDOException $e) {
				$this->err = $e->getMessage();
			}
		}
		
		private function direction($chk, $direction) {
			if ($direction == "up") {
				foreach (array_values(array_reverse($this->getRows)) as $row) {
					if ($row < $chk) {
						$new = $row;
						break;
					} elseif ($chk == current($this->getRows)) {
						$new = $chk;
						break;
					}
				}
			} elseif ($direction == "down") {
				foreach (array_values($this->getRows) as $row) {
					if ($row > $chk) {
						$new = $row;
						break;
					} elseif ($chk == end($this->getRows)) {
						$new = $chk;
						break;
					}
				}
			} 
			if (!is_null($new)) {
				return $new;
			} else {
				$this->err = "Error, could no swap values.";
			}
		}

		public function swap($rowNum, $where = "up" || "down") {
			try {
				$this->pdo->exec("
					CALL ".$this->sp."(".$rowNum.",".$this->direction($rowNum, $where).");
				");
			} catch (PDOException $e) {
				$this->err = $e->getMessage();
			}
		}

		private function createSP($sp_name) {
			$this->sp = $sp_name;
			try {
				$this->pdo->beginTransaction();
				$this->pdo->exec("
					CREATE PROCEDURE IF NOT EXISTS `".$this->sp."` (
						`p_to_be_moved` INT, 
						`p_to_be_swapped` INT)

						BEGIN
							DECLARE `moved` INT;
							DECLARE `swapped` INT;
							
							SET 
								`moved` = `p_to_be_moved`,
								`swapped` = `p_to_be_swapped`;
							
							UPDATE `".$this->table."`
								SET `".$this->column."` = CASE
									WHEN (`".$this->column."` = `moved`) THEN `swapped`
									WHEN (`".$this->column."` = `swapped`) THEN `moved`
								END
							WHERE `".$this->column."` IN(`moved`, `swapped`);
						END;
				");
				$this->pdo->commit();
			} catch (PDOException $e) {
				$this->err = $e->getMessage();
				$this->pdo->rollBack();
				$this->pdo->exec("DROP PROCEDURE IF EXISTS `".$this->sp."`;");
			}
		}

		private function createUDF($udf_name) {
			$this->udf = $udf_name;
			try {
				$this->pdo->exec("
					CREATE FUNCTION IF NOT EXISTS `".$this->udf."` ()
						RETURNS INT(10)
						BEGIN
							DECLARE `getCount` INT(10);
							
							SET `getCount` = (
								SELECT COUNT(`".$this->column."`) AS cnt
								FROM `".$this->table."`) + 1;
								
							RETURN `getCount`;
						END;
				");
			} catch (PDOException $e) {
				$this->err = $e->getMessage();
			}
		}

		public function __destruct() {
			$this->showErr();
		}
	}

?>