<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2026 Carlos García Gómez <carlos@facturascripts.com>
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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\LogMessage;
use FacturaScripts\Dinamic\Model\Subcuenta;

/**
 * Elimina los registros que los tests generan de forma indirecta y que no se borran con el modelo
 * que los origina: eventos de la cola de trabajos, logs de los cron y de auditoría, y subcuentas
 * de los clientes y proveedores creadas al contabilizar las facturas.
 */
trait CleanTestDataTrait
{
    /** @var int[] */
    private static $lastIds = [];

    protected static function saveLastIds(): void
    {
        // instanciamos los modelos para asegurarnos de que existen las tablas
        new LogMessage();
        new Subcuenta();
        new WorkEvent();

        self::$lastIds = [
            'logs' => self::lastId(LogMessage::table(), 'id'),
            'subcuentas' => self::lastId(Subcuenta::table(), 'idsubcuenta'),
            'work_events' => self::lastId(WorkEvent::table(), 'id'),
        ];
    }

    private static function lastId(DbQuery $query, string $field): int
    {
        // no usamos max(): con la tabla vacía MAX() devuelve NULL y DbQuery::maxString() lanza un TypeError
        $row = $query->select($field)->orderBy($field, 'DESC')->first();
        return (int)($row[$field] ?? 0);
    }

    protected static function deleteNewRecords(): void
    {
        // primero las subcuentas, porque al eliminarlas se generan eventos y logs
        $where = [Where::gt('idsubcuenta', self::$lastIds['subcuentas'])];
        foreach (Subcuenta::all($where, [], 0, 0) as $subcuenta) {
            self::assertTrue($subcuenta->delete(), 'subcuenta-cant-delete-' . $subcuenta->codsubcuenta);
        }

        // eliminamos también los eventos pendientes, para que no se ejecuten en otros tests
        WorkEvent::deleteWhere([Where::gt('id', self::$lastIds['work_events'])]);
        LogMessage::deleteWhere([Where::gt('id', self::$lastIds['logs'])]);
    }
}
