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
use FacturaScripts\Plugins\StockAvanzado\Model\ConteoStock;

/**
 * Está clase, es para crear movimientos iniciales de stock
 * cuando el producto tiene stock, pero no tiene movimientos.
 *
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class InitialStockMovementManager
{
    /** @var DataBase */
    protected static $db;

    /** @var int|null */
    protected static $idproducto = null;

    public static function initial(?int $idproducto = null, array &$messages = [], bool $cron = false): void
    {
        static::$idproducto = $idproducto;

        // recorremos los almacénes
        foreach (Almacenes::all() as $warehouse) {
            // para cada almacén, obtenemos todos los stocks de cada variante
            // siempre que dicha variante no tenga movimientos de stock
            $stocks = static::getStocks($warehouse->codalmacen);
            if (empty($stocks)) {
                continue;
            }

            // si hay stock sin movimientos
            // creamos un conteo inicial de stock
            $count = new ConteoStock();
            $count->codalmacen = $warehouse->codalmacen;
            $count->observaciones = Tools::trans('initial-stock-movements');
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

        // si hemos ejecutado la clase desde el cron, terminamos
        if ($cron) {
            return;
        }

        // mostramos los mensajes
        foreach ($messages as $message) {
            Tools::log()->warning($message);
        }
    }

    protected static function getStocks(string $codalmacen): array
    {
        $sql = "SELECT *"
            . " FROM stocks"
            . " WHERE codalmacen = " . self::db()->var2str($codalmacen);

        if (null !== static::$idproducto) {
            $sql .= " AND idproducto = " . self::db()->var2str(static::$idproducto);
        }

        $sql .= " AND cantidad <> 0"
            . " AND referencia NOT IN (SELECT referencia FROM stocks_movimientos WHERE codalmacen = "
            . self::db()->var2str($codalmacen);

        if (null !== static::$idproducto) {
            $sql .= " AND idproducto = " . self::db()->var2str(static::$idproducto);
        }

        $sql .= ")";

        return self::db()->select($sql);
    }

    protected static function db(): DataBase
    {
        if (null === static::$db) {
            static::$db = new DataBase();
            static::$db->connect();
        }

        return static::$db;
    }
}
