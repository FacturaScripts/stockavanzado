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
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Email\MailNotifier;
use FacturaScripts\Dinamic\Lib\Email\TextBlock;
use FacturaScripts\Dinamic\Lib\Email\TitleBlock;
use FacturaScripts\Dinamic\Model\User;

/**
 * Esta clase sirve para corregir los ids de los productos en las líneas y movimientos.
 *
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class StockMinMaxManager
{
    const NOTIFICATION_STOCK_MIN = 'stock_min';
    const NOTIFICATION_STOCK_MAX = 'stock_max';

    /** @var DataBase */
    protected static $db;

    public static function notify(array &$messages = [], bool $cron = false): void
    {
        static::$db = new DataBase();
        static::stockMin($messages);
        static::stockMax($messages);

        // si hemos ejecutado la clase desde el cron, terminamos
        if ($cron) {
            return;
        }

        // mostramos los mensajes
        foreach ($messages as $message) {
            Tools::log()->warning($message);
        }
    }

    private static function getBlocks(array $stocks): array
    {
        $blocks = [];
        foreach (static::getStocksFormatted($stocks) as $codalmacen => $references) {
            $name = explode('|', $codalmacen);
            $blocks[] = new TitleBlock($name[1], 'h4');
            foreach ($references as $reference) {
                $blocks[] = new TextBlock('- ' . $reference);
            }
        }
        return $blocks;
    }

    private static function getStocksFormatted(array $stocks): array
    {
        $formatted = [];
        foreach ($stocks as $stock) {
            $warehouse = Almacenes::get($stock['codalmacen']);
            $key = $stock['codalmacen'] . '|' . $warehouse->nombre;
            if (!isset($formatted[$key])) {
                $formatted[$key] = [];
            }

            $formatted[$key][] = $stock['referencia'];
        }

        return $formatted;
    }

    private static function getUsers(): array
    {
        $where = [Where::column('admin', true)];
        return User::all($where);
    }
    private static function stockMax(array &$messages): void
    {
        $messages[] = '- Búsqueda de productos con stock máximo ...';

        // creamos una sql para obtener todos los stocks
        // con el campo 'stockmax' rellenado y diferente de 0,
        // el campo 'cantidad' sea mayor o igual que el campo 'stockmax'
        // y sa_notification_max sea nulo
        $sql = 'SELECT *'
            . ' FROM stocks s'
            . ' WHERE s.stockmax IS NOT NULL'
            . ' AND s.stockmax <> 0'
            . ' AND s.cantidad >= s.stockmax'
            . ' AND s.sa_notification_max IS NULL';

        // obtenemos los datos
        $stocks = static::$db->select($sql);

        // si no hay datos, salimos
        if (empty($stocks)) {
            $messages[] = '-- No se han encontrado productos con stock máximo que notificar.';
            return;
        }

        // recorremos los usuarios administradores
        $notified = false;
        foreach (static::getUsers() as $user) {
            $result = MailNotifier::send(
                static::NOTIFICATION_STOCK_MAX,
                $user->email,
                $user->nick,
                ['nick' => $user->nick],
                [],
                static::getBlocks($stocks),
            );

            if (!$result) {
                $messages[] = '-- Error al enviar la notificación de stock máximo a ' . $user->nick;
                continue;
            }

            $notified = true;
        }

        // si no se ha notificado a ningún usuario, salimos
        if (!$notified) {
            $messages[] = '-- No se han podido enviar notificaciones de stock máximo.';
            return;
        }

        // marcamos cada stock como notificado con la fecha actual por sql
        foreach ($stocks as $stock) {
            static::$db->exec('UPDATE stocks'
                . ' SET sa_notification_max = ' . static::$db->var2str(Tools::dateTime())
                . ' WHERE idstock =' . static::$db->var2str($stock['idstock'])
            );
        }
    }

    private static function stockMin(array &$messages): void
    {
        $messages[] = '- Búsqueda de productos con stock mínimo ...';

        // creamos una sql para obtener todos los stocks
        // con el campo 'stockmin' rellenado y diferente de 0,
        // el campo 'cantidad' sea menor o igual que el campo 'stockmin'
        // y sa_notification_min sea nulo
        $sql = 'SELECT *'
            . ' FROM stocks s'
            . ' WHERE s.stockmin IS NOT NULL'
            . ' AND s.stockmin <> 0'
            . ' AND s.cantidad <= s.stockmin'
            . ' AND s.sa_notification_min IS NULL';

        // obtenemos los datos
        $stocks = static::$db->select($sql);

        // si no hay datos, salimos
        if (empty($stocks)) {
            $messages[] = '-- No se han encontrado productos con stock mínimo que notificar.';
            return;
        }

        // recorremos los usuarios administradores
        $notified = false;
        foreach (static::getUsers() as $user) {
            $result = MailNotifier::send(
                static::NOTIFICATION_STOCK_MIN,
                $user->email,
                $user->nick,
                ['nick' => $user->nick],
                [],
                static::getBlocks($stocks),
            );

            if (!$result) {
                $messages[] = '-- Error al enviar la notificación de stock mínimo a ' . $user->nick;
                continue;
            }

            $notified = true;
        }

        // si no se ha notificado a ningún usuario, salimos
        if (!$notified) {
            $messages[] = '-- No se han podido enviar notificaciones de stock mínimo.';
            return;
        }

        // marcamos cada stock como notificado con la fecha actual por sql
        foreach ($stocks as $stock) {
            static::$db->exec('UPDATE stocks'
                . ' SET sa_notification_min = ' . static::$db->var2str(Tools::dateTime())
                . ' WHERE idstock =' . static::$db->var2str($stock['idstock'])
            );
        }
    }
}
