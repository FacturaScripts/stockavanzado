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
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Email\MailNotifier;
use FacturaScripts\Dinamic\Lib\Email\TextBlock;
use FacturaScripts\Dinamic\Lib\Email\TitleBlock;
use FacturaScripts\Dinamic\Model\User;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
final class StockMinMax extends CronJobClass
{
    public const JOB_NAME = 'stock-min-max';
    const NOTIFICATION_STOCK_MIN = 'stock_min';
    const NOTIFICATION_STOCK_MAX = 'stock_max';

    public static function run(): void
    {
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');
        self::stockMin();
        self::stockMax();
        self::saveEcho();
    }

    private static function getBlocks(array $stocks): array
    {
        $blocks = [];
        foreach (self::getStocksFormatted($stocks) as $codalmacen => $references) {
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
    private static function stockMax(): void
    {
        self::echo("\n- Búsqueda de productos con stock máximo ...");

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
        $db = new DataBase();
        $stocks = $db->select($sql);

        // si no hay datos, salimos
        if (empty($stocks)) {
            self::echo("\n-- No se han encontrado productos con stock máximo que notificar.");
            return;
        }

        // recorremos los usuarios administradores
        $notified = false;
        foreach (self::getUsers() as $user) {
            $result = MailNotifier::send(
                self::NOTIFICATION_STOCK_MAX,
                $user->email,
                $user->nick,
                ['nick' => $user->nick],
                [],
                self::getBlocks($stocks),
            );

            if (!$result) {
                self::echo("\n-- Error al enviar la notificación de stock máximo a " . $user->nick);
                continue;
            }

            $notified = true;
        }

        // si no se ha notificado a ningún usuario, salimos
        if (!$notified) {
            self::echo("\n-- No se han podido enviar notificaciones de stock máximo.");
            return;
        }

        // marcamos cada stock como notificado con la fecha actual por sql
        foreach ($stocks as $stock) {
            $db->exec('UPDATE stocks'
                . ' SET sa_notification_max = ' . $db->var2str(Tools::dateTime())
                . ' WHERE idstock =' . $db->var2str($stock['idstock'])
            );
        }

        self::echo("\n-- Notificaciones de stock máximo enviadas correctamente.");
    }

    private static function stockMin(): void
    {
        self::echo("\n- Búsqueda de productos con stock mínimo ...");

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
        $db = new DataBase();
        $stocks = $db->select($sql);

        // si no hay datos, salimos
        if (empty($stocks)) {
            self::echo("\n-- No se han encontrado productos con stock mínimo que notificar.");
            return;
        }

        // recorremos los usuarios administradores
        $notified = false;
        foreach (self::getUsers() as $user) {
            $result = MailNotifier::send(
                self::NOTIFICATION_STOCK_MIN,
                $user->email,
                $user->nick,
                ['nick' => $user->nick],
                [],
                self::getBlocks($stocks),
            );

            if (!$result) {
                self::echo("\n-- Error al enviar la notificación de stock mínimo a " . $user->nick);
                continue;
            }

            $notified = true;
        }

        // si no se ha notificado a ningún usuario, salimos
        if (!$notified) {
            self::echo("\n-- No se han podido enviar notificaciones de stock mínimo.");
            return;
        }

        // marcamos cada stock como notificado con la fecha actual por sql
        foreach ($stocks as $stock) {
            $db->exec('UPDATE stocks'
                . ' SET sa_notification_min = ' . $db->var2str(Tools::dateTime())
                . ' WHERE idstock =' . $db->var2str($stock['idstock'])
            );
        }

        self::echo("\n-- Notificaciones de stock mínimo enviadas correctamente.");
    }
}