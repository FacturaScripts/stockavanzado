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
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class StockRebuild
{
    /** @var DataBase */
    private static $database;

    /** @var int|null */
    protected static $idproducto = null;

    public static function rebuild(?int $idproducto = null): bool
    {
        if (false === self::dataBase()->tableExists('stocks_movimientos')) {
            return false;
        }

        static::$idproducto = $idproducto;

        $newTransaction = false === static::dataBase()->inTransaction() && self::dataBase()->beginTransaction();
        static::clear();

        foreach (Almacenes::all() as $war) {
            foreach (static::calculateStockData($war->codalmacen) as $data) {
                $stock = new Stock();
                $where = [
                    Where::column('codalmacen', $data['codalmacen']),
                    Where::column('referencia', $data['referencia'])
                ];
                if ($stock->loadWhere($where)) {
                    // el stock ya existe
                    $stock->loadFromData($data);
                } else {
                    // creamos un nuevo stock
                    $stock = new Stock($data);
                }

                if (false === $stock->save()) {
                    Tools::log()->error('error-saving-stock');
                    Tools::log('audit')->error('error-saving-stock');
                    if ($newTransaction) {
                        self::dataBase()->rollBack();
                    }
                    return false;
                }
            }
        }

        if ($newTransaction) {
            self::dataBase()->commit();
        }

        Tools::log('audit')->warning('rebuilt-stock');
        return true;
    }

    protected static function clear(): void
    {
        if (false === self::dataBase()->tableExists('stocks')) {
            return;
        }

        $sqlStock = "UPDATE stocks SET cantidad = '0', disponible = '0', pterecibir = '0', reservada = '0'";
        $sqlVariante = "UPDATE variantes SET stockfis = '0'";
        $sqlProducto = "UPDATE productos SET stockfis = '0'";
        if (null !== static::$idproducto) {
            $sqlStock .= " WHERE idproducto = " . self::dataBase()->var2str(static::$idproducto) . ";";
            $sqlVariante .= " WHERE idproducto = " . self::dataBase()->var2str(static::$idproducto) . ";";
            $sqlProducto .= " WHERE idproducto = " . self::dataBase()->var2str(static::$idproducto) . ";";
        }

        self::dataBase()->exec($sqlStock);
        self::dataBase()->exec($sqlVariante);
        self::dataBase()->exec($sqlProducto);
    }

    protected static function calculateStockData(string $codalmacen): array
    {
        // obtenemos un array de referencias únicas
        $sql = "SELECT referencia"
            . " FROM stocks_movimientos"
            . " WHERE codalmacen = " . self::dataBase()->var2str($codalmacen);
        if (null !== static::$idproducto) {
            $sql .= " AND idproducto = " . self::dataBase()->var2str(static::$idproducto);
        }
        $sql .= " GROUP BY 1";
        $rows = self::dataBase()->select($sql);

        // si no hay referencias, devolvemos array vacío
        if (empty($rows)) {
            return [];
        }

        $stockData = [];
        foreach ($rows as $row) {
            $where = [
                Where::column('codalmacen', $codalmacen),
                Where::column('referencia', $row['referencia'])
            ];

            // obtenemos el último movimiento de cada referencia
            $lastMovement = new MovimientoStock();
            $lastMovement->loadWhere($where, ['fecha' => 'DESC', 'hora' => 'DESC', 'id' => 'DESC']);

            // sumamos todos los movimientos de cada referencia para obtener el stock actual
            $stockSumMovements = 0.0;
            foreach (MovimientoStock::all($where, ['fecha' => 'ASC', 'hora' => 'ASC', 'id' => 'ASC']) as $movement) {
                $stockSumMovements += $movement->cantidad;
            }

            // si hay último movimiento, y la suma de movimientos es distinta al saldo del último movimiento, avisamos
            if ($lastMovement->id() && $lastMovement->saldo !== $stockSumMovements) {
                $stock = $lastMovement->saldo;
                Tools::log()->warning('stock-rebuild-inconsistency-detected', ['%referencia%' => $row['referencia'], '%codalmacen%' => $codalmacen, '%last_saldo%' => $lastMovement->saldo, '%sum_movements%' => $stockSumMovements]);
                Tools::log('audit')->warning('stock-rebuild-inconsistency-detected', ['%referencia%' => $row['referencia'], '%codalmacen%' => $codalmacen, '%last_saldo%' => $lastMovement->saldo, '%sum_movements%' => $stockSumMovements]);
            } else {
                $stock = $stockSumMovements;
            }

            $ref = trim($row['referencia']);
            $stockData[$ref] = [
                'cantidad' => $stock,
                'codalmacen' => $codalmacen,
                'pterecibir' => 0,
                'referencia' => $ref,
                'reservada' => 0
            ];
        }

        static::setPterecibir($stockData, $codalmacen);
        static::setReservada($stockData, $codalmacen);
        return $stockData;
    }

    protected static function dataBase(): DataBase
    {
        if (!isset(self::$database)) {
            self::$database = new DataBase();
        }

        return self::$database;
    }

    protected static function setPterecibir(array &$stockData, string $codalmacen): void
    {
        if (false === self::dataBase()->tableExists('lineaspedidosprov')) {
            return;
        }

        $sql = "SELECT l.referencia,"
            . " SUM(CASE WHEN l.cantidad > l.servido THEN l.cantidad - l.servido ELSE 0 END) as pte"
            . " FROM lineaspedidosprov l"
            . " JOIN pedidosprov p ON l.idpedido = p.idpedido"
            . " JOIN variantes v ON v.referencia = l.referencia"
            . " WHERE l.referencia IS NOT NULL";

        if (null !== static::$idproducto) {
            $sql .= " AND l.idproducto = " . self::dataBase()->var2str(static::$idproducto);
        }

        $sql .= " AND l.actualizastock = '2'"
            . " AND p.codalmacen = " . self::dataBase()->var2str($codalmacen)
            . " GROUP BY 1;";
        foreach (self::dataBase()->select($sql) as $row) {
            $ref = trim($row['referencia']);
            if (!isset($stockData[$ref])) {
                $stockData[$ref] = [
                    'cantidad' => 0,
                    'codalmacen' => $codalmacen,
                    'referencia' => $ref,
                    'reservada' => 0
                ];
            }

            $stockData[$ref]['pterecibir'] = (float)$row['pte'];
        }
    }

    protected static function setReservada(array &$stockData, string $codalmacen): void
    {
        if (false === self::dataBase()->tableExists('lineaspedidoscli')) {
            return;
        }

        $sql = "SELECT l.referencia,"
            . " SUM(CASE WHEN l.cantidad > l.servido THEN l.cantidad - l.servido ELSE 0 END) as reservada"
            . " FROM lineaspedidoscli l"
            . " JOIN pedidoscli p ON l.idpedido = p.idpedido"
            . " JOIN variantes v ON v.referencia = l.referencia"
            . " WHERE l.referencia IS NOT NULL";

        if (null !== static::$idproducto) {
            $sql .= " AND l.idproducto = " . self::dataBase()->var2str(static::$idproducto);
        }

        $sql .= " AND l.actualizastock = '-2'"
            . " AND p.codalmacen = " . self::dataBase()->var2str($codalmacen)
            . " GROUP BY 1;";
        foreach (self::dataBase()->select($sql) as $row) {
            $ref = trim($row['referencia']);
            if (!isset($stockData[$ref])) {
                $stockData[$ref] = [
                    'cantidad' => 0,
                    'codalmacen' => $codalmacen,
                    'pterecibir' => 0,
                    'referencia' => $ref
                ];
            }

            $stockData[$ref]['reservada'] = (float)$row['reservada'];
        }
    }
}
