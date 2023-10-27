<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Model\Almacen;

class StockValue
{
    const JOB_NAME = 'stock-value';
    const JOB_PERIOD = '1 hour';

    /** @var DataBase */
    private static $dataBase;

    public static function update(Almacen $warehouse): bool
    {
        // ponemos el stock valorado a 0
        $warehouse->stock_valorado_coste = 0.0;
        $warehouse->stock_valorado_precio = 0.0;

        // calculamos el stock valorado
        $sql = 'SELECT s.referencia, s.cantidad, v.coste, v.precio'
            . ' FROM stocks AS s'
            . ' LEFT JOIN variantes AS v ON v.referencia = s.referencia'
            . ' WHERE s.codalmacen = ' . self::db()->var2str($warehouse->codalmacen)
            . ' AND s.cantidad > 0';
        $limit = 1000;
        $offset = 0;
        while ($rows = self::db()->selectLimit($sql, $limit, $offset)) {
            foreach ($rows as $row) {
                $warehouse->stock_valorado_coste += $row['cantidad'] * $row['coste'];
                $warehouse->stock_valorado_precio += $row['cantidad'] * $row['precio'];
            }

            $offset += $limit;
        }

        // guardamos los cambios
        $warehouse->stock_valorado_coste = round($warehouse->stock_valorado_coste, FS_NF0);
        $warehouse->stock_valorado_precio = round($warehouse->stock_valorado_precio, FS_NF0);
        return $warehouse->save();
    }

    public static function updateAll(): bool
    {
        // recorremos todos los almacenes
        foreach (Almacenes::all() as $warehouse) {
            if (false === static::update($warehouse)) {
                return false;
            }
        }

        return true;
    }

    private static function db(): DataBase
    {
        if (null === self::$dataBase) {
            self::$dataBase = new DataBase();
        }

        return self::$dataBase;
    }
}