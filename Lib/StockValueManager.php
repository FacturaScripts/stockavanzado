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
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Plugins\StockAvanzado\Model\StockValoradoHistorico;

/**
 * Esta clase sirve para establecer el stock valorado de los almacenes.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class StockValueManager
{
    /** @var DataBase */
    private static $db;

    public static function calculate(?string $codalmacen = null, array &$messages = [], bool $cron = false): void
    {
        // Guardar histórico día a día por almacen
        $fecha = Tools::date();
        $whereAlm = empty($codalmacen) ? [] : [Where::eq('codalmacen', $codalmacen)];
        foreach (Almacen::all($whereAlm) as $warehouse) {
            $hist = new StockValoradoHistorico();
            $whereHist = [Where::eq('codalmacen', $warehouse->codalmacen), Where::eq('fecha', $fecha)];
            if(false === $hist->loadWhere($whereHist)){
                $messages[] = '- Error al guardar histórico del almacén ' . $warehouse->codalmacen;
                return;
            }; // load if exists, ignore result

            $hist->codalmacen = $warehouse->codalmacen;
            $hist->fecha = $fecha;
            $hist->total_coste = (float)$warehouse->stock_valorado_coste;
            $hist->total_precio = (float)$warehouse->stock_valorado_precio;
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
}
