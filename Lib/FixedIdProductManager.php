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

namespace FacturaScripts\Plugins\StockAvanzado\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

/**
 * Esta clase sirve para corregir los ids de los productos en las líneas y movimientos.
 *
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class FixedIdProductManager
{
    /** @var DataBase */
    protected static $db;
    
    public static function fixed(array &$messages = [], bool $cron = false): void
    {
        static::$db = new DataBase();
        static::checkLinesIDs('lineaspresupuestosprov', $messages);
        static::checkLinesIDs('lineaspedidosprov', $messages);
        static::checkLinesIDs('lineasalbaranesprov', $messages);
        static::checkLinesIDs('lineasfacturasprov', $messages);
        static::checkLinesIDs('lineaspresupuestoscli', $messages);
        static::checkLinesIDs('lineaspedidoscli', $messages);
        static::checkLinesIDs('lineasalbaranescli', $messages);
        static::checkLinesIDs('lineasfacturascli', $messages);
        static::checkLinesIDs('stocks_lineasconteos', $messages);
        static::checkLinesIDs('stocks_lineastransferencias', $messages);
        static::checkIDs('stocks_movimientos', $messages);

        // si hemos ejecutado la clase desde el cron, terminamos
        if ($cron) {
            return;
        }

        // mostramos los mensajes
        foreach ($messages as $message) {
            Tools::log()->warning($message);
        }
    }

    protected static function checkIDs(string $table, array &$messages): void
    {
        // si la tabla no existe, salimos
        if (!static::$db->tableExists($table)) {
            $messages[] = '- La tabla ' . $table . ' no existe.';
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
        $result = static::$db->select($sql);

        // si no hay datos, salimos
        if (empty($result)) {
            $messages[] = '- No hay productos con id incorrecto en la tabla ' . $table;
            return;
        }

        // recorremos los datos
        foreach ($result as $row) {
            $messages[] = '- Corrigiendo id del producto ' . $row['referencia'] . ' en el movimiento ' . $row['id'] . ' de la tabla ' . $table . ' (de ' . $row['idproducto_linea'] . ' a ' . $row['idproducto_variante'] . ')';
            $update = static::$db->exec('UPDATE ' . $table . ' SET idproducto = ' . $row['idproducto_variante'] . ' WHERE id = ' . $row['id'] . ';');
            if (!$update) {
                $messages[] = '- Error al actualizar el id del producto en el movimiento ' . $row['id'] . ' de la tabla ' . $table;
            }
        }
    }

    protected static function checkLinesIDs(string $table, array &$messages): void
    {
        // si la tabla no existe, salimos
        if (!static::$db->tableExists($table)) {
            $messages[] = '- La tabla ' . $table . ' no existe.';
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
        $result = static::$db->select($sql);

        // si no hay datos, salimos
        if (empty($result)) {
            $messages[] = '- No hay productos con id incorrecto en la tabla ' . $table;
            return;
        }

        // recorremos los datos
        foreach ($result as $row) {
            $messages[] = '- Corrigiendo id del producto ' . $row['referencia'] . ' en la línea ' . $row['idlinea'] . ' de la tabla ' . $table . ' (de ' . $row['idproducto_linea'] . ' a ' . $row['idproducto_variante'] . ')';
            $update = static::$db->exec('UPDATE ' . $table . ' SET idproducto = ' . $row['idproducto_variante'] . ' WHERE idlinea = ' . $row['idlinea'] . ';');
            if (!$update) {
                $messages[] = '- Error al actualizar el id del producto en la línea ' . $row['idlinea'] . ' de la tabla ' . $table;
            }
        }
    }
}