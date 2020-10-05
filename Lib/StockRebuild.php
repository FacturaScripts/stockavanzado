<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020 Carlos Garcia Gomez <carlos@facturascripts.com>
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
            /// calculate stock from movements
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

            /// save stock
            foreach ($stockData as $data) {
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
     * 
     * @return bool
     */
    protected static function clear()
    {
        $database = new DataBase();
        if ($database->tableExists('stocks')) {
            return $database->exec("UPDATE stocks SET cantidad = '0', disponible = '0';");
        }

        return true;
    }
}
