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
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
use FacturaScripts\Core\Model\Base\TransformerDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\LineaAlbaranCliente;
use FacturaScripts\Dinamic\Model\LineaAlbaranProveedor;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\LineaFacturaCliente;
use FacturaScripts\Dinamic\Model\LineaFacturaProveedor;
use FacturaScripts\Dinamic\Model\LineaTransferenciaStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\TransferenciaStock;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\StockAvanzado\Contract\StockMovementModInterface;

/**
 * Está clase se encarga de gestionar los movimientos de stock.
 *
 * @author       Carlos Garcia Gomez            <carlos@facturascripts.com>
 * @collaborator Jerónimo Pedro Sánchez Manzano <socger@gmail.com>
 */
class StockMovementManager
{
    /** @var array */
    private static $docStates = [];

    /** @var int|null */
    private static $idproducto = null;

    /** @var StockMovementModInterface[] */
    private static $mods = [];

    /** @var array */
    private static $products = [];

    /** @var array */
    private static $variants = [];

    public static function addLineBusinessDocument(BusinessDocumentLine $line, TransformerDocument $doc): void
    {
        // si el estado del documento no actualiza stock, salimos
        if (false === in_array($line->actualizastock, [1, -1], true) &&
            false === in_array($line->getOriginal('actualizastock'), [1, -1], true)) {
            return;
        }

        // buscamos si ya existe el movimiento
        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $doc->codalmacen),
            Where::eq('docid', $doc->id()),
            Where::eq('docmodel', $doc->modelClassName()),
            Where::eq('referencia', $line->referencia)
        ];
        if (false === $movement->loadWhere($where)) {
            // no existe, lo creamos
            $movement->codalmacen = $doc->codalmacen;
            $movement->docid = $doc->id();
            $movement->docmodel = $doc->modelClassName();
            $movement->idproducto = $line->idproducto ?? $line->getProducto()->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($line->cantidad)) {
                return;
            }
        }

        // actualizamos la cantidad
        $movement->cantidad -= in_array($line->getOriginal('actualizastock'), [-1, 0, 1]) ?
            $line->getOriginal('actualizastock') * $line->getOriginal('cantidad') : 0;
        $movement->cantidad += in_array($line->actualizastock, [-1, 0, 1]) ?
            $line->actualizastock * $line->cantidad : 0;
        $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . $doc->codigo;
        $movement->fecha = $doc->fecha;
        $movement->hora = $doc->hora;

        // actualizamos el saldo
        $previousSaldo = static::getPreviousSaldo($movement->codalmacen, $movement->referencia, $movement->fecha, $movement->hora);
        $movement->saldo = $previousSaldo + $movement->cantidad;

        empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    public static function addLineCounting(LineaConteoStock $line, ConteoStock $stockCount, float $stock): bool
    {
        $docid = $stockCount->id();
        $docmodel = $stockCount->modelClassName();

        // buscamos el movimiento existente
        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $stockCount->codalmacen),
            Where::eq('docid', $docid),
            Where::eq('docmodel', $docmodel),
            Where::eq('referencia', $line->referencia)
        ];

        if (false === $movement->loadWhere($where)) {
            // no existe, lo creamos
            $movement->documento = Tools::trans($stockCount->modelClassName()) . ' ' . $stockCount->id();
            $movement->fecha = Tools::date($stockCount->fechafin);
            $movement->hora = Tools::hour($stockCount->fechafin);
            $movement->codalmacen = $stockCount->codalmacen;
            $movement->docid = $docid;
            $movement->docmodel = $docmodel;
            $movement->idproducto = $line->idproducto;
            $movement->referencia = $line->referencia;
        }

        // actualizamos la cantidad y el saldo
        $movement->cantidad = 0;
        $movement->saldo = $stock;

        return $movement->save();
    }

    public static function addLineTransferStock(LineaTransferenciaStock $line, TransferenciaStock $transfer): void
    {
        static::addLineTransferStockMovement($transfer->codalmacenorigen, $line->cantidad * -1, $transfer, $line);
        static::addLineTransferStockMovement($transfer->codalmacendestino, $line->cantidad, $transfer, $line);
    }

    public static function addMod(StockMovementModInterface $mod): void
    {
        static::$mods[] = $mod;
    }

    public static function addTransferLine(BusinessDocumentLine $line, TransformerDocument $doc, string $fromCodalmacen, string $toCodalmacen): void
    {
        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $fromCodalmacen),
            Where::eq('docid', $doc->id()),
            Where::eq('docmodel', $doc->modelClassName()),
            Where::eq('referencia', $line->referencia)
        ];
        if ($movement->loadWhere($where)) {
            $movement->codalmacen = $toCodalmacen;
            $movement->save();
        }
    }

    public static function deleteLineCounting(LineaConteoStock $line, ConteoStock $stockCount): bool
    {
        $docid = $stockCount->id();
        $docmodel = $stockCount->modelClassName();

        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $stockCount->codalmacen),
            Where::eq('docid', $docid),
            Where::eq('docmodel', $docmodel),
            Where::eq('referencia', $line->referencia)
        ];
        if (false === $movement->loadWhere($where)) {
            return true;
        }

        return $movement->delete();
    }

    public static function deleteLineTransfer(LineaTransferenciaStock $line, TransferenciaStock $stockCount): bool
    {
        if (false === static::deleteLineTransferMovement($stockCount->codalmacenorigen, $line, $stockCount)) {
            return false;
        }

        return static::deleteLineTransferMovement($stockCount->codalmacendestino, $line, $stockCount);
    }

    public static function rebuild(?int $idproducto = null, array &$messages = [], bool $cron = false): void
    {
        self::setIdProducto($idproducto);

        // eliminamos todos los movimientos de stock
        if (!static::deleteMovements()) {
            $messages[] = 'error-deleting-movements';
            Tools::log('audit')->warning('error-deleting-movements');
            return;
        }

        if (!empty(static::$idproducto)) {
            static::rebuildBusinessDocument();

            // creamos los movimientos de las transferencias de stock
            static::rebuildTransferStock();

            // creamos los movimientos de los conteos de stock
            static::rebuildStockCounting();
        }

        // añadimos los mods para otros plugins
        foreach (static::$mods as $mod) {
            $mod->run(static::$idproducto);
        }

        // reseteamos el idproducto
        self::setIdProducto(null);

        // si hemos ejecutado la clase desde el cron, terminamos
        if ($cron) {
            return;
        }

        // mostramos los mensajes
        foreach ($messages as $message) {
            Tools::log('audit')->warning($message);
        }
    }

    public static function setIdProducto(?int $idproducto): void
    {
        static::$idproducto = $idproducto;
    }

    protected static function addLineTransferStockMovement(string $codalmacen, float $cantidad, TransferenciaStock $transfer, LineaTransferenciaStock $line): bool
    {
        // buscamos el movimiento existente
        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('docid', $transfer->id()),
            Where::eq('docmodel', $transfer->modelClassName()),
            Where::eq('referencia', $line->referencia)
        ];
        if (false === $movement->loadWhere($where)) {
            // no existe, lo creamos
            $movement->documento = Tools::trans($transfer->modelClassName()) . ' ' . $transfer->id();
            $movement->fecha = Tools::date($transfer->fecha);
            $movement->hora = Tools::hour($transfer->fecha);
            $movement->codalmacen = $codalmacen;
            $movement->docid = $transfer->id();
            $movement->docmodel = $transfer->modelClassName();
            $movement->idproducto = $line->getVariant()->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($cantidad)) {
                return true;
            }
        }

        // actualizamos la cantidad
        $movement->cantidad += $cantidad;

        // actualizamos el saldo
        $previousSaldo = static::getPreviousSaldo($movement->codalmacen, $movement->referencia, $movement->fecha, $movement->hora);
        $movement->saldo = $previousSaldo + $movement->cantidad;

        return empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    protected static function deleteLineTransferMovement(string $codalmacen, LineaTransferenciaStock $line, TransferenciaStock $stockCount): bool
    {
        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('docid', $stockCount->id()),
            Where::eq('docmodel', $stockCount->modelClassName()),
            Where::eq('referencia', $line->referencia)
        ];
        if ($movement->loadWhere($where) && false === $movement->delete()) {
            return false;
        }

        return true;
    }

    public static function deleteMovements(): bool
    {
        $db = new DataBase();

        $sql = 'DELETE FROM stocks_movimientos';
        if (!is_null(static::$idproducto)) {
            $sql .= ' WHERE idproducto = ' . $db->var2str(static::$idproducto);
        }

        return $db->exec($sql);
    }

    protected static function getPreviousSaldo(string $codalmacen, string $referencia, string $fecha, string $hora): float
    {
        // buscamos el último movimiento anterior
        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('referencia', $referencia),
            Where::lte('fecha', $fecha)
        ];
        $order = ['fecha' => 'DESC', 'hora' => 'DESC'];
        foreach (MovimientoStock::all($where, $order, 0, 50) as $movement) {
            // nos aseguramos de que la hora también sea anterior
            if (strtotime($fecha . ' ' . $hora) < strtotime($movement->fecha . ' ' . $movement->hora)) {
                return $movement->saldo;
            }
        }

        return 0;
    }

    protected static function getProduct(string $reference): Producto
    {
        $variant = static::getVariant($reference);

        if (!isset(static::$products[$variant->idproducto])) {
            static::$products[$variant->idproducto] = $variant->getProducto();
        }

        return static::$products[$variant->idproducto];
    }

    protected static function getVariant(string $referencia): Variante
    {
        if (!isset(static::$variants[$referencia])) {
            $variante = new Variante();
            $variante->loadWhereEq('referencia', $referencia);
            static::$variants[$referencia] = $variante;
        }

        return static::$variants[$referencia];
    }

    protected static function rebuildBusinessDocument(): void
    {
        $limit = 250;
        $models = [
            new LineaAlbaranProveedor(), new LineaFacturaProveedor(), new LineaAlbaranCliente(),
            new LineaFacturaCliente()
        ];
        foreach ($models as $model) {
            $where = [
                Where::notEq('actualizastock', 0),
                Where::notEq('cantidad', 0),
                Where::eq('idproducto', static::$idproducto),
                Where::isNotNull('referencia'),
            ];
            $offset = 0;

            $lines = $model->all($where, ['idlinea' => 'ASC'], $offset, $limit);
            while (count($lines) > 0) {
                foreach ($lines as $line) {
                    // Omitir productos faltantes o productos sin gestión de stock
                    $product = static::getProduct($line->referencia);
                    if (empty($product->idproducto) || $product->nostock) {
                        continue;
                    }

                    $doc = $line->getDocument();

                    // En rebuild, creamos movimientos nuevos directamente
                    $movement = new MovimientoStock();
                    $whereMov = [
                        Where::eq('codalmacen', $doc->codalmacen),
                        Where::eq('docid', $doc->id()),
                        Where::eq('docmodel', $doc->modelClassName()),
                        Where::eq('referencia', $line->referencia)
                    ];

                    if (false === $movement->loadWhere($whereMov)) {
                        // no existe, lo creamos
                        $movement->codalmacen = $doc->codalmacen;
                        $movement->docid = $doc->id();
                        $movement->docmodel = $doc->modelClassName();
                        $movement->idproducto = $line->idproducto ?? $line->getProducto()->idproducto;
                        $movement->referencia = $line->referencia;

                        $movement->cantidad = in_array($line->actualizastock, [-1, 0, 1]) ?
                            $line->actualizastock * $line->cantidad : 0;
                        if (empty($movement->cantidad)) {
                            continue;
                        }

                        $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . $doc->codigo;
                        $movement->fecha = $doc->fecha;
                        $movement->hora = $doc->hora;
                        $movement->save();
                        continue;
                    }

                    // ya existe, actualizamos la cantidad
                    $movement->cantidad += $line->actualizastock * $line->cantidad;
                    if (empty($model->cantidad)) {
                        $movement->delete();
                    } else {
                        $movement->save();
                    }
                }

                $offset += $limit;
                $lines = $model->all($where, ['idlinea' => 'ASC'], $offset, $limit);
            }
        }
    }

    protected static function rebuildStockCounting(): void
    {
        // recorremos todas las líneas de conteo de stock
        $where = [Where::eq('idproducto', static::$idproducto)];
        foreach (LineaConteoStock::all($where, ['idlinea' => 'ASC']) as $line) {
            // si el conteo no está finalizado, lo omitimos
            $conteo = $line->getConteo();
            if (!$conteo->completed) {
                continue;
            }

            // ahora generamos el movimiento de stock
            static::addLineCounting($line, $conteo, $line->cantidad);
        }
    }

    protected static function rebuildTransferStock(): void
    {
        // recorremos todas las líneas de transferencias de stock
        $where = [Where::eq('idproducto', static::$idproducto)];
        foreach (LineaTransferenciaStock::all($where, ['idlinea' => 'ASC']) as $line) {
            // si la transferencia no está finalizada, la omitimos
            $transfer = $line->getTransference();
            if (!$transfer->completed) {
                continue;
            }

            static::addLineTransferStock($line, $transfer);
        }
    }
}
