<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2023-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\StockValueManager;

final class StockValue extends CronJobClass
{
    const JOB_NAME = 'stock-value';
    const JOB_PERIOD = '1 hour';

    public static function run(?string $codalmacen = null): void
    {
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');

        $messages = [];
        StockValueManager::calculate($codalmacen, $messages, true);

        // Guardar histórico día a día por almacén
        try {
            $db = new \FacturaScripts\Core\Base\DataBase();
            $db->connect();
            $fecha = \FacturaScripts\Core\Tools::date();
            $whereAlm = empty($codalmacen) ? [] : [\FacturaScripts\Core\Where::column('codalmacen', $codalmacen)];
            foreach (\FacturaScripts\Dinamic\Model\Almacen::all($whereAlm) as $warehouse) {
                $hist = new \FacturaScripts\Plugins\StockAvanzado\Model\StockValoradoHistorico();
                $whereHist = [\FacturaScripts\Core\Where::column('codalmacen', $warehouse->codalmacen), \FacturaScripts\Core\Where::eq('fecha', $fecha)];
                $hist->loadWhere($whereHist); // load if exists, ignore result

                $hist->codalmacen = $warehouse->codalmacen;
                $hist->fecha = $fecha;
                $hist->total_coste = (float)$warehouse->stock_valorado_coste;
                $hist->total_precio = (float)$warehouse->stock_valorado_precio;

                // construir detalles
                $detalles = [];
                $sql = 'SELECT s.referencia, s.cantidad, v.coste, v.precio'
                    . ' FROM stocks AS s'
                    . ' JOIN variantes AS v ON v.referencia = s.referencia'
                    . ' WHERE s.codalmacen = ' . $db->var2str($warehouse->codalmacen)
                    . ' AND s.cantidad > 0';

                $limit = 1000;
                $offset = 0;
                while ($rows = $db->selectLimit($sql, $limit, $offset)) {
                    foreach ($rows as $row) {
                        $detalles[] = [
                            'referencia' => $row['referencia'],
                            'cantidad' => (float)$row['cantidad'],
                            'coste' => (float)$row['coste'],
                            'precio' => (float)$row['precio'],
                            'valor_coste' => (float)$row['cantidad'] * (float)$row['coste'],
                            'valor_precio' => (float)$row['cantidad'] * (float)$row['precio']
                        ];
                    }

                    $offset += $limit;
                }

                $hist->detalles = json_encode($detalles, JSON_UNESCAPED_UNICODE);

                if (false === $hist->save()) {
                    $messages[] = '- Error al guardar histórico del almacén ' . $warehouse->codalmacen;
                }
            }
        } catch (\Throwable $e) {
            Tools::log()->error('stock-valorado-historico-save-failed: ' . $e->getMessage());
        }

        foreach ($messages as $message) {
            self::echo("\n- " . Tools::trans($message));
        }

        self::saveEcho();
    }
}
