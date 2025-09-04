<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
final class FixedIdProduct extends CronJobClass
{
    const JOB_NAME = 'fixed-id-product';
    const JOB_PERIOD = '1 year';

    /** @var DataBase */
    private static $db;

    public static function run(): void
    {
        self::$db = new DataBase();

        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');
        self::checkLinesIDs('lineaspresupuestosprov');
        self::checkLinesIDs('lineaspedidosprov');
        self::checkLinesIDs('lineasalbaranesprov');
        self::checkLinesIDs('lineasfacturasprov');
        self::checkLinesIDs('lineaspresupuestoscli');
        self::checkLinesIDs('lineaspedidoscli');
        self::checkLinesIDs('lineasalbaranescli');
        self::checkLinesIDs('lineasfacturascli');
        self::checkLinesIDs('stocks_lineasconteos');
        self::checkLinesIDs('stocks_lineastransferencias');
        self::checkIDs('stocks_movimientos');
        self::saveEcho();
    }

    private static function checkIDs(string $table): void
    {
        // si la tabla no existe, salimos
        if (!self::$db->tableExists($table)) {
            self::echo("\n- La tabla " . $table . ' no existe.');
            return;
        }

        // creamos la sql
        $sql = 'SELECT lf.id,'
            . ' lf.idproducto AS idproducto_linea,'
            . ' v.idproducto AS idproducto_variante,'
            . ' lf.referencia'
            . ' FROM ' . $table . ' lf'
            . ' JOIN variantes v ON v.referencia = lf.referencia'
            . ' WHERE lf.idproducto <> v.idproducto;';

        // obtenemos los datos
        $result = self::$db->select($sql);

        // si no hay datos, salimos
        if (empty($result)) {
            self::echo("\n- No hay productos con id incorrecto en la tabla " . $table);
            return;
        }

        // recorremos los datos
        foreach ($result as $row) {
            self::echo("\n- Corrigiendo id del producto " . $row['referencia'] . ' en el movimiento ' . $row['id'] . ' de la tabla ' . $table . ' (de ' . $row['idproducto_linea'] . ' a ' . $row['idproducto_variante'] . ')');
            $update = self::$db->exec('UPDATE ' . $table . ' SET idproducto = ' . $row['idproducto_variante'] . ' WHERE id = ' . $row['id'] . ';');
            if (!$update) {
                self::echo("\n- Error al actualizar el id del producto en el movimiento " . $row['id'] . ' de la tabla ' . $table);
            }
        }
    }

    private static function checkLinesIDs(string $table): void
    {
        // si la tabla no existe, salimos
        if (!self::$db->tableExists($table)) {
            self::echo("\n- La tabla " . $table . ' no existe.');
            return;
        }

        // creamos la sql
        $sql = 'SELECT lf.idlinea,'
            . ' lf.idproducto AS idproducto_linea,'
            . ' v.idproducto AS idproducto_variante,'
            . ' lf.referencia'
            . ' FROM ' . $table . ' lf'
            . ' JOIN variantes v ON v.referencia = lf.referencia'
            . ' WHERE lf.idproducto <> v.idproducto;';

        // obtenemos los datos
        $result = self::$db->select($sql);

        // si no hay datos, salimos
        if (empty($result)) {
            self::echo("\n- No hay productos con id incorrecto en la tabla " . $table);
            return;
        }

        // recorremos los datos
        foreach ($result as $row) {
            self::echo("\n- Corrigiendo id del producto " . $row['referencia'] . ' en la línea ' . $row['idlinea'] . ' de la tabla ' . $table . ' (de ' . $row['idproducto_linea'] . ' a ' . $row['idproducto_variante'] . ')');
            $update = self::$db->exec('UPDATE ' . $table . ' SET idproducto = ' . $row['idproducto_variante'] . ' WHERE idlinea = ' . $row['idlinea'] . ';');
            if (!$update) {
                self::echo("\n- Error al actualizar el id del producto en la línea " . $row['idlinea'] . ' de la tabla ' . $table);
            }
        }
    }
}