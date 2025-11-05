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
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
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
        if (false === in_array($line->actualizastock, [1, -1], true) &&
            false === in_array($line->getOriginal('actualizastock'), [1, -1], true)) {
            return;
        }

        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $doc->codalmacen),
            Where::eq('docid', $doc->id()),
            Where::eq('docmodel', $doc->modelClassName()),
            Where::eq('referencia', $line->referencia)
        ];
        if (false === $movement->loadWhere($where)) {
            $movement->codalmacen = $doc->codalmacen;
            $movement->docid = $doc->id();
            $movement->docmodel = $doc->modelClassName();
            $movement->idproducto = $line->idproducto ?? $line->getProducto()->idproducto;
            $movement->referencia = $line->referencia;
            if (empty($line->cantidad)) {
                return;
            }
        }

        $movement->cantidad -= $line->getOriginal('actualizastock') * $line->getOriginal('cantidad');
        $movement->cantidad += $line->actualizastock * $line->cantidad;
        $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . $doc->codigo;
        $movement->fecha = $doc->fecha;
        $movement->hora = $doc->hora;
        empty($movement->cantidad) ? $movement->delete() : $movement->save();
    }

    public static function addLineCounting(LineaConteoStock $line, ConteoStock $stockCount, float $stock): bool
    {
        $docid = $stockCount->id();
        $docmodel = $stockCount->modelClassName();

        // Calculamos el stock acumulado hasta la fecha del conteo (excluyendo el conteo actual)
        $stockSum = static::getStockSum($line->referencia, $stockCount->codalmacen, $docid, $docmodel, $stockCount->fechafin);

        // El movimiento del conteo debe ajustar el stock para que quede exactamente en el valor contado
        // Fórmula: cantidad_movimiento = stock_objetivo - stock_calculado_antes_del_conteo
        $cantidad = $stock - $stockSum;

        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $stockCount->codalmacen),
            Where::eq('docid', $docid),
            Where::eq('docmodel', $docmodel),
            Where::eq('referencia', $line->referencia)
        ];

        if (false === $movement->loadWhere($where)) {
            $movement->documento = Tools::trans($stockCount->modelClassName()) . ' ' . $stockCount->id();
            $movement->fecha = Tools::date($stockCount->fechafin);
            $movement->hora = Tools::hour($stockCount->fechafin);
            $movement->codalmacen = $stockCount->codalmacen;
            $movement->docid = $docid;
            $movement->docmodel = $docmodel;
            $movement->idproducto = $line->idproducto;
            $movement->referencia = $line->referencia;
        }

        $movement->cantidad = $cantidad;

        // Solo guardar si hay cantidad diferente de 0, si no eliminar el movimiento
        if ($cantidad == 0) {
            return $movement->exists() ? $movement->delete() : true;
        }

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
        static::$idproducto = $idproducto;

        // eliminamos todos los movimientos de stock
        if (!static::deleteMovements()) {
            $messages[] = 'error-deleting-movements';
            Tools::log('audit')->warning('error-deleting-movements');
            return;
        }

        if (empty(static::$idproducto)) {
            // creamos los movimientos de los documentos de compra y venta
            static::rebuildBusinessDocuments();

            // creamos los movimientos de las transferencias de stock
            static::rebuildTransferStock();

            // creamos los movimientos de los conteos de stock
            static::rebuildStockCounting();
        } else {
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

        // si hemos ejecutado la clase desde el cron, terminamos
        if ($cron) {
            return;
        }

        // mostramos los mensajes
        foreach ($messages as $message) {
            Tools::log('audit')->warning($message);
        }
    }

    protected static function addLineTransferStockMovement(string $codalmacen, float $cantidad, TransferenciaStock $transfer, LineaTransferenciaStock $line): bool
    {
        $movement = new MovimientoStock();
        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('docid', $transfer->id()),
            Where::eq('docmodel', $transfer->modelClassName()),
            Where::eq('referencia', $line->referencia)
        ];
        if (false === $movement->loadWhere($where)) {
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

        $movement->cantidad += $cantidad;
        return empty($movement->cantidad) ? $movement->delete() : $movement->save();
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

    protected static function deleteMovements(): bool
    {
        $db = new DataBase();

        $sql = 'DELETE FROM stocks_movimientos';
        if (!is_null(static::$idproducto)) {
            $sql .= ' WHERE idproducto = ' . $db->var2str(static::$idproducto);
        }

        return $db->exec($sql);
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

    protected static function getStockSum(string $reference, string $codalmacen, int $docid, string $docmodel, string $datetime): float
    {
        $sum = 0.0;
        $targetTimestamp = strtotime($datetime);

        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('referencia', $reference)
        ];

        // Obtener todos los movimientos ordenados cronológicamente
        $movements = MovimientoStock::all($where, ['fecha' => 'ASC', 'hora' => 'ASC', 'id' => 'ASC']);

        foreach ($movements as $move) {
            $moveTimestamp = strtotime($move->fecha . ' ' . $move->hora);

            // Excluir el movimiento actual (mismo documento) ANTES de verificar la fecha
            if ($move->docid == $docid && $move->docmodel == $docmodel) {
                continue;
            }

            // Solo procesar movimientos anteriores al datetime objetivo
            if ($moveTimestamp > $targetTimestamp) {
                break; // Como está ordenado, ya no hay movimientos anteriores
            }

            $sum += $move->cantidad;
        }

        return $sum;
    }

    protected static function ignoredBusinessDocumentState(TransformerDocument $doc): bool
    {
        // comprobar o agregar el estado a la lista
        if (!isset(static::$docStates[$doc->idestado])) {
            static::$docStates[$doc->idestado] = $doc->getStatus();
        }

        // Ignorar estado con actualizastock == 0
        return empty(static::$docStates[$doc->idestado]->actualizastock);
    }

    protected static function rebuildBusinessDocument(): void
    {
        $limit = 500;
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
                        $movement->cantidad = $line->actualizastock * $line->cantidad;
                        $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . $doc->codigo;
                        $movement->fecha = $doc->fecha;
                        $movement->hora = $doc->hora;
                        $movement->save();
                        continue;
                    }

                    // ya existe, actualizamos la cantidad
                    $movement->cantidad += $line->actualizastock * $line->cantidad;
                    $movement->save();
                }

                $offset += $limit;
                $lines = $model->all($where, ['idlinea' => 'ASC'], $offset, $limit);
            }
        }
    }

    protected static function rebuildBusinessDocuments(): void
    {
        $limit = 500;
        $models = [new AlbaranProveedor(), new FacturaProveedor(), new AlbaranCliente(), new FacturaCliente()];
        foreach ($models as $model) {
            $offset = 0;
            $docs = $model->all([], ['fecha' => 'ASC'], $offset, $limit);

            while (count($docs) > 0) {
                foreach ($docs as $doc) {
                    // Saltar estados que no modifican el stock
                    if (static::ignoredBusinessDocumentState($doc)) {
                        continue;
                    }

                    foreach ($doc->getLines() as $line) {
                        // saltamos líneas sin referencia
                        if (empty($line->referencia)) {
                            continue;
                        }

                        // Omitir productos faltantes o productos sin gestión de stock
                        $product = static::getProduct($line->referencia);
                        if (empty($product->idproducto) || $product->nostock) {
                            continue;
                        }

                        // omitimos el producto si no es el que buscamos
                        if (null !== static::$idproducto && $product->idproducto !== static::$idproducto) {
                            continue;
                        }

                        // En rebuild, creamos movimientos nuevos directamente
                        $movement = new MovimientoStock();
                        $where = [
                            Where::eq('codalmacen', $doc->codalmacen),
                            Where::eq('docid', $doc->id()),
                            Where::eq('docmodel', $doc->modelClassName()),
                            Where::eq('referencia', $line->referencia)
                        ];

                        // Solo crear si no existe ya
                        if (false === $movement->loadWhere($where)) {
                            $movement->codalmacen = $doc->codalmacen;
                            $movement->docid = $doc->id();
                            $movement->docmodel = $doc->modelClassName();
                            $movement->idproducto = $line->idproducto ?? $line->getProducto()->idproducto;
                            $movement->referencia = $line->referencia;
                            $movement->cantidad = $line->actualizastock * $line->cantidad;
                            $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . $doc->codigo;
                            $movement->fecha = $doc->fecha;
                            $movement->hora = $doc->hora;

                            if (!empty($movement->cantidad)) {
                                $movement->save();
                            }
                        }
                    }
                }

                $offset += $limit;
                $docs = $model->all([], ['fecha' => 'DESC'], $offset, $limit);
            }
        }
    }

    protected static function rebuildStockCounting(): void
    {
        $where = [Where::eq('completed', true)];
        foreach (ConteoStock::all($where, ['idconteo' => 'ASC']) as $conteo) {
            // primero recorremos las líneas para obtener el stock actual por referencia
            $stocks = [];
            foreach ($conteo->getLines() as $line) {
                if (false === isset($stocks[$line->referencia])) {
                    $stocks[$line->referencia] = $line->cantidad;
                    continue;
                }
                $stocks[$line->referencia] += $line->cantidad;
            }

            // ahora generamos los movimientos de stock
            foreach ($conteo->getLines(['fecha' => 'ASC']) as $line) {
                $product = $line->getProducto();

                // omitimos el producto si no es el que buscamos
                if (null !== static::$idproducto && $product->idproducto !== static::$idproducto) {
                    continue;
                }

                static::addLineCounting($line, $conteo, $stocks[$line->referencia]);
            }
        }
    }

    protected static function rebuildTransferStock(): void
    {
        $where = [Where::eq('completed', true)];
        foreach (TransferenciaStock::all($where, ['idtrans' => 'ASC']) as $transfer) {
            foreach ($transfer->getLines() as $line) {
                $variant = $line->getVariant();
                $product = $variant->getProducto();

                // omitimos el producto si no es el que buscamos
                if (null !== static::$idproducto && $product->idproducto !== static::$idproducto) {
                    continue;
                }

                static::addLineTransferStock($line, $transfer);
            }
        }
    }
}
