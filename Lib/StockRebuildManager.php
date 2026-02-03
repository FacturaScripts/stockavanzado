<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\StockAvanzado\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\KernelException;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;

/**
 * Está clase sirve para recalcular el stock de todos los productos
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class StockRebuildManager
{
    /** @var DataBase */
    private static $db;

    /** @var int|null */
    protected static $idproducto = null;

    public static function rebuild(?int $idproducto = null, array &$messages = [], bool $cron = false): void
    {
        if (false === static::db()->tableExists('stocks_movimientos')) {
            $messages[] = 'stock-rebuild-no-table-movements';
            return;
        }

        self::setIdProducto($idproducto);

        $newTransaction = false === static::db()->inTransaction() && static::db()->beginTransaction();
        static::clear();

        foreach (Almacenes::all() as $war) {
            foreach (static::calculateStockData($war->codalmacen) as $data) {
                $stock = new Stock();
                $where = [
                    Where::eq('codalmacen', $data['codalmacen']),
                    Where::eq('referencia', $data['referencia'])
                ];
                if ($stock->loadWhere($where)) {
                    // el stock ya existe
                    $stock->loadFromData($data);
                } else {
                    // creamos un nuevo stock
                    $stock = new Stock($data);
                }

                if (false === $stock->save()) {
                    $messages[] = 'error-saving-stock';
                    if ($newTransaction) {
                        static::db()->rollBack();
                    }
                    return;
                }
            }
        }

        if ($newTransaction) {
            static::db()->commit();
        }

        self::setIdProducto(null);

        // si hemos ejecutado la clase desde el cron, terminamos
        if ($cron) {
            return;
        }

        // mostramos los mensajes
        foreach ($messages as $message) {
            Tools::log('audit')->warning($message);
        }
    }

    protected static function clear(): void
    {
        if (false === static::db()->tableExists('stocks')) {
            return;
        }

        $sqlStock = "UPDATE stocks SET cantidad = '0', disponible = '0', pterecibir = '0', reservada = '0'";
        $sqlVariante = "UPDATE variantes SET stockfis = '0'";
        $sqlProducto = "UPDATE productos SET stockfis = '0'";
        if (null !== static::$idproducto) {
            $sqlStock .= " WHERE idproducto = " . static::db()->var2str(static::$idproducto) . ";";
            $sqlVariante .= " WHERE idproducto = " . static::db()->var2str(static::$idproducto) . ";";
            $sqlProducto .= " WHERE idproducto = " . static::db()->var2str(static::$idproducto) . ";";
        }

        static::db()->exec($sqlStock);
        static::db()->exec($sqlVariante);
        static::db()->exec($sqlProducto);
    }

    protected static function calculateStockData(string $codalmacen): array
    {
        $stockData = [];
        static::setPterecibir($stockData, $codalmacen);
        static::setReservada($stockData, $codalmacen);

        // obtenemos un array de referencias únicas
        $sql = "SELECT referencia"
            . " FROM stocks_movimientos"
            . " WHERE codalmacen = " . static::db()->var2str($codalmacen);
        if (null !== static::$idproducto) {
            $sql .= " AND idproducto = " . static::db()->var2str(static::$idproducto);
        }
        $sql .= " GROUP BY 1";
        $rows = static::db()->select($sql);

        // si no hay referencias, devolvemos array vacío
        if (empty($rows)) {
            return $stockData;
        }

        foreach ($rows as $row) {
            $where = [
                Where::eq('codalmacen', $codalmacen),
                Where::eq('referencia', $row['referencia'])
            ];

            // obtenemos el último movimiento de cada referencia
            $lastMovement = new MovimientoStock();
            $lastMovement->loadWhere($where, ['fecha' => 'DESC', 'hora' => 'DESC', 'id' => 'DESC']);

            $ref = trim($row['referencia']);
            if (!isset($stockData[$ref])) {
                $stockData[$ref] = [
                    'codalmacen' => $codalmacen,
                    'pterecibir' => 0,
                    'referencia' => $ref,
                    'reservada' => 0
                ];
            }
            $stockData[$ref]['cantidad'] = $lastMovement->saldo;
        }

        return $stockData;
    }

    protected static function db(): DataBase
    {
        if (!isset(static::$db)) {
            static::$db = new DataBase();
        }

        return static::$db;
    }

    public static function setIdProducto(?int $idproducto): void
    {
        static::$idproducto = $idproducto;
    }

    /**
     * Calcula las cantidades pendientes de recibir para un almacén
     * para cada tipo de documento de proveedor.
     *
     * @param array $stockData
     * @param string $codalmacen
     * @return void
     * @throws KernelException
     */
    protected static function setPterecibir(array &$stockData, string $codalmacen): void
    {
        static::applyPteRecibirFromTable($stockData, $codalmacen, 'pedidosprov', 'idpedido');
        static::applyPteRecibirFromTable($stockData, $codalmacen, 'albaranesprov', 'idalbaran');
        static::applyPteRecibirFromTable($stockData, $codalmacen, 'facturasprov', 'idfactura');
    }

    /**
     * Calcula las cantidades reservadas para un almacén
     * para cada tipo de documento de cliente.
     *
     * @param array $stockData
     * @param string $codalmacen
     * @return void
     * @throws KernelException
     */
    protected static function setReservada(array &$stockData, string $codalmacen): void
    {
        static::applyReservadaFromTable($stockData, $codalmacen, 'pedidoscli', 'idpedido');
        static::applyReservadaFromTable($stockData, $codalmacen, 'albaranescli', 'idalbaran');
        static::applyReservadaFromTable($stockData, $codalmacen, 'facturascli', 'idfactura');
    }

    /**
     * Aplica las cantidades pendientes de recibir desde una tabla específica
     *
     * @param array $stockData
     * @param string $codalmacen
     * @param string $table
     * @param string $field
     * @return void
     * @throws KernelException
     */
    private static function applyPteRecibirFromTable(array &$stockData, string $codalmacen, string $table, string $field): void
    {
        $linesTable = 'lineas' . $table;
        if (false === static::db()->tableExists($linesTable)) {
            return;
        }

        $sql = "SELECT l.referencia,"
            . " SUM(CASE WHEN l.cantidad > l.servido THEN l.cantidad - l.servido ELSE 0 END) as pte"
            . " FROM {$linesTable} l"
            . " JOIN {$table} p ON p.{$field} = l.{$field}"
            . " WHERE l.referencia IS NOT NULL";

        if (null !== static::$idproducto) {
            $sql .= " AND l.idproducto = " . static::db()->var2str(static::$idproducto);
        }

        $sql .= " AND l.actualizastock = '2'"
            . " AND p.codalmacen = " . static::db()->var2str($codalmacen)
            . " GROUP BY 1;";

        foreach (static::db()->select($sql) as $row) {
            $ref = trim($row['referencia']);
            if (!isset($stockData[$ref])) {
                $stockData[$ref] = [
                    'cantidad' => 0,
                    'codalmacen' => $codalmacen,
                    'referencia' => $ref,
                    'reservada' => 0,
                    'pterecibir' => 0,
                ];
            }

            $stockData[$ref]['pterecibir'] += (float)$row['pte'];
        }
    }

    /**
     * Aplica las cantidades reservadas desde una tabla específica
     *
     * @param array $stockData
     * @param string $codalmacen
     * @param string $table
     * @param string $field
     * @return void
     * @throws KernelException
     */
    private static function applyReservadaFromTable(array &$stockData, string $codalmacen, string $table, string $field): void
    {
        $linesTable = 'lineas' . $table;
        if (false === static::db()->tableExists($linesTable)) {
            return;
        }

        $sql = "SELECT l.referencia,"
            . " SUM(CASE WHEN l.cantidad > l.servido THEN l.cantidad - l.servido ELSE 0 END) as reservada"
            . " FROM {$linesTable} l"
            . " JOIN {$table} p ON p.{$field} = l.{$field}"
            . " WHERE l.referencia IS NOT NULL";

        if (null !== static::$idproducto) {
            $sql .= " AND l.idproducto = " . static::db()->var2str(static::$idproducto);
        }

        $sql .= " AND l.actualizastock = '-2'"
            . " AND p.codalmacen = " . static::db()->var2str($codalmacen)
            . " GROUP BY 1;";

        foreach (static::db()->select($sql) as $row) {
            $ref = trim($row['referencia']);
            if (!isset($stockData[$ref])) {
                $stockData[$ref] = [
                    'cantidad' => 0,
                    'codalmacen' => $codalmacen,
                    'pterecibir' => 0,
                    'referencia' => $ref,
                    'reservada' => 0,
                ];
            }

            $stockData[$ref]['reservada'] += (float)$row['reservada'];
        }
    }
}
