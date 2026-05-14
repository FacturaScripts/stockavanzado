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

namespace FacturaScripts\Plugins\StockAvanzado\CronJob;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Template\CronJobClass;

/**
 * Tarea de mantenimiento que corrige el campo idproducto en las líneas de documentos
 * (presupuestos, pedidos, albaranes y facturas, tanto de compra como de venta), en las
 * líneas de conteos y transferencias de stock y en los movimientos de stock.
 *
 * Para cada registro busca la referencia en la tabla variantes y, si el idproducto no
 * coincide o está vacío, lo actualiza con el valor correcto. De este modo se mantiene
 * la coherencia entre referencia e idproducto cuando alguna fuente externa los ha
 * dejado desincronizados.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class FixedIdProduct extends CronJobClass
{
    const JOB_NAME = 'fixed-id-product';
    const JOB_PERIOD = '1 month';

    private static $db;

    public static function run(): void
    {
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');

        self::fixTable('lineaspresupuestosprov', 'idlinea');
        self::fixTable('lineaspedidosprov', 'idlinea');
        self::fixTable('lineasalbaranesprov', 'idlinea');
        self::fixTable('lineasfacturasprov', 'idlinea');

        self::fixTable('lineaspresupuestoscli', 'idlinea');
        self::fixTable('lineaspedidoscli', 'idlinea');
        self::fixTable('lineasalbaranescli', 'idlinea');
        self::fixTable('lineasfacturascli', 'idlinea');

        self::fixTable('stocks_lineasconteos', 'idlinea');
        self::fixTable('stocks_lineastransferencias', 'idlinea');
        self::fixTable('stocks_movimientos', 'id');

        self::saveEcho();
    }

    protected static function fixTable(string $table, string $pk): void
    {
        // si la tabla no existe, salimos
        if (!self::db()->tableExists($table)) {
            return;
        }

        // buscamos registros con idproducto incorrecto
        $sql = 'SELECT lf.' . $pk . ' AS pk,'
            . ' v.idproducto AS idproducto_variante,'
            . ' lf.referencia'
            . ' FROM ' . $table . ' lf'
            . ' JOIN variantes v ON v.referencia = lf.referencia'
            . ' WHERE lf.idproducto IS NULL OR lf.idproducto <> v.idproducto;';

        $result = self::db()->select($sql);
        if (empty($result)) {
            return;
        }

        foreach ($result as $row) {
            $idproducto = (int)$row['idproducto_variante'];
            $pkValue = (int)$row['pk'];

            $update = self::db()->exec('UPDATE ' . $table . ' SET idproducto = ' . $idproducto
                . ' WHERE ' . $pk . ' = ' . $pkValue . ';');

            if (!$update) {
                self::echo("\n-- Error al corregir el idproducto en el registro " . $pkValue . ' de la tabla ' . $table);
                continue;
            }

            self::echo("\n-- Corregido idproducto de " . $row['referencia']
                . ' en el registro ' . $pkValue . ' de la tabla ' . $table);
        }
    }

    protected static function db(): DataBase
    {
        if (self::$db === null) {
            self::$db = new DataBase();
            self::$db->connect();
        }

        return self::$db;
    }
}
