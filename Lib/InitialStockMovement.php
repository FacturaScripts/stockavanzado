<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2024-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\ConteoStock;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class InitialStockMovement
{
    const JOB_NAME = 'initial-stock-movements';
    const JOB_PERIOD = '99 years';

    /** @var DataBase */
    protected static $dataBase;

    public static function run(): void
    {
        self::$dataBase = new DataBase();

        // recorremos los almacénes
        foreach (Almacenes::all() as $warehouse) {
            // para cada almacén, obtenemos todos los stocks de cada variante
            // siempre que dicha variante no tenga movimientos de stock
            $stocks = self::getStocks($warehouse->codalmacen);

            // si no hay stocks, continuamos
            if (empty($stocks)) {
                continue;
            }

            // si hay stock sin movimientos
            // creamos un conteo inicial de stock
            $count = new ConteoStock();
            $count->codalmacen = $warehouse->codalmacen;
            $count->observaciones = Tools::lang()->trans('initial-stock-movements');
            if (false === $count->save()) {
                continue;
            }

            // recorremos los stocks y añadimos cada stock al conteo
            foreach ($stocks as $stock) {
                $count->addLine($stock['referencia'], $stock['idproducto'], $stock['cantidad']);
            }

            // procesamos el conteo
            $count->updateStock();
        }
    }

    protected static function getStocks(string $codalmacen): array
    {
        $sql = "SELECT *"
            . " FROM stocks"
            . " WHERE codalmacen = " . self::$dataBase->var2str($codalmacen)
            . " AND cantidad <> 0"
            . " AND referencia NOT IN (SELECT referencia FROM stocks_movimientos WHERE codalmacen = " . self::$dataBase->var2str($codalmacen) . ")";

        return self::$dataBase->select($sql);
    }
}
