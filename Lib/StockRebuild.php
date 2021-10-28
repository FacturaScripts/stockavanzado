<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2021 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;

/**
 * Description of StockRebuild
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class StockRebuild
{

    public static function rebuild()
    {
        static::clear();

        $warehouse = new Almacen();
        foreach ($warehouse->all([], [], 0, 0) as $war) {
            // calculate stock from movements
            $stockData = [];
            $stockMovement = new MovimientoStock();
            $where = [new DataBaseWhere('codalmacen', $war->codalmacen)];
            foreach ($stockMovement->all($where, ['referencia' => 'ASC'], 0, 0) as $move) {
                if (!isset($stockData[$move->referencia])) {
                    $stockData[$move->referencia] = [
                        'cantidad' => $move->cantidad,
                        'codalmacen' => $war->codalmacen,
                        'idproducto' => $move->idproducto,
                        'referencia' => $move->referencia
                    ];
                    continue;
                }

                $stockData[$move->referencia]['cantidad'] += $move->cantidad;
            }

            // save stock
            foreach ($stockData as $data) {
                // we calculate the rest of the fields
                $data['pterecibir'] = static::getPterecibir($data['referencia']);
                $data['reservada'] = static::getReservada($data['referencia']);

                $stock = new Stock();
                $where2 = [
                    new DataBaseWhere('codalmacen', $data['codalmacen']),
                    new DataBaseWhere('referencia', $data['referencia'])
                ];
                if ($stock->loadFromCode('', $where2)) {
                    $stock->loadFromData($data);
                    $stock->save();
                    continue;
                }

                $newStock = new Stock($data);
                $newStock->save();
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    protected static function clear()
    {
        $database = new DataBase();
        if ($database->tableExists('stocks')) {
            $sql = "UPDATE stocks SET cantidad = '0', disponible = '0', pterecibir = '0', reservada = '0';";
            return $database->exec($sql);
        }

        return true;
    }

    /**
     * @param string $ref
     *
     * @return float
     */
    protected static function getPterecibir(string $ref): float
    {
        $database = new DataBase();
        if ($database->tableExists('lineaspedidosprov')) {
            $sql = "SELECT SUM(cantidad) as pte FROM lineaspedidosprov WHERE actualizastock = '2'"
                . " AND referencia = " . $database->var2str($ref) . ";";
            foreach ($database->select($sql) as $row) {
                return (float)$row['pte'];
            }
        }

        return 0.0;
    }

    /**
     * @param string $ref
     *
     * @return float
     */
    protected static function getReservada(string $ref): float
    {
        $database = new DataBase();
        if ($database->tableExists('lineaspedidoscli')) {
            $sql = "SELECT SUM(cantidad) as reservada FROM lineaspedidoscli WHERE actualizastock = '-2'"
                . " AND referencia = " . $database->var2str($ref) . ";";
            foreach ($database->select($sql) as $row) {
                return (float)$row['reservada'];
            }
        }

        return 0.0;
    }
}
