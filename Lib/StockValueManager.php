<?php

/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\StockValoradoHistorico;

/**
 * Esta clase sirve para establecer el stock valorado de los almacenes.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class StockValueManager
{
    public static function calculate(?string $codalmacen = null, array &$messages = [], bool $cron = false): void
    {
        $db = new DataBase();
        $fecha = Tools::date();

        // obtener los almacenes o el almacén que se ha pasado como parámetro
        $whereAlm = empty($codalmacen ) ? [] : [Where::eq('codalmacen', $codalmacen )];
        $warehouses = Almacen::all($whereAlm);

        foreach ($warehouses as $warehouse) {
            $hist = new StockValoradoHistorico();
            $whereHist = [
                Where::eq('codalmacen', $warehouse->codalmacen),
                Where::eq('fecha', $fecha)
            ];

            // si ya existe un histórico para ese día y ese almacén, saltamos
            if ($hist->loadWhere($whereHist)) {
                $messages[] = '- Ya existe un histórico para el almacén ' . $warehouse->codalmacen . ' en la fecha ' . $fecha . ', se ha saltado la creación de este histórico';
                continue;
            };

            $hist->codalmacen = $warehouse->codalmacen;
            $hist->fecha = $fecha;
            $hist->total_coste = static::getCost($db, $warehouse);
            $hist->total_precio = static::getPrice($db, $warehouse);
            if (false === $hist->save()) {
                $messages[] = '- Error al guardar histórico del almacén ' . $warehouse->codalmacen;
            }
        }

        // si hemos ejecutado la clase desde el cron, terminamos
        if ($cron) {
            return;
        }

        // mostramos los mensajes
        foreach ($messages as $message) {
            Tools::log()->warning($message);
        }
    }

    protected static function getCost(DataBase $db, Almacen $warehouse): float
    {
        $total = 0.0;

        // calculamos el coste valorado
        $sql = 'SELECT s.referencia, s.cantidad, v.coste'
            . ' FROM stocks AS s'
            . ' JOIN variantes AS v ON v.referencia = s.referencia'
            . ' WHERE s.codalmacen = ' . $db->var2str($warehouse->id())
            . ' AND s.cantidad > 0';

        $limit = 1000;
        $offset = 0;
        while ($rows = $db->selectLimit($sql, $limit, $offset)) {
            foreach ($rows as $row) {
                $total += $row['cantidad'] * $row['coste'];
            }

            $offset += $limit;
        }

        return $total;
    }

    protected static function getPrice(DataBase $db, Almacen $warehouse): float
    {
        $total = 0.0;

        // calculamos el precio valorado
        $sql = 'SELECT s.referencia, s.cantidad, v.precio'
            . ' FROM stocks AS s'
            . ' JOIN variantes AS v ON v.referencia = s.referencia'
            . ' WHERE s.codalmacen = ' . $db->var2str($warehouse->id())
            . ' AND s.cantidad > 0';

        $limit = 1000;
        $offset = 0;
        while ($rows = $db->selectLimit($sql, $limit, $offset)) {
            foreach ($rows as $row) {
                $total += $row['cantidad'] * $row['precio'];
            }

            $offset += $limit;
        }

        return $total;
    }
}
