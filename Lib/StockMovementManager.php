<?php

/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2026 Carlos García Gómez <carlos@facturascripts.com>
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
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\DocTransformation;
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
 * Para evitar recalcular el saldo de una referencia una y otra vez mientras se guarda
 * un documento (o mientras se genera uno nuevo a partir de otro), esta clase dispone de
 * un sistema de lotes: entre beginBatch() y flushBatch() los movimientos no se crean de
 * inmediato, sino que se anota el par (documento, referencia) afectado y, al cerrar el
 * lote, se calcula una sola vez cada movimiento y una sola vez cada saldo.
 *
 * @author       Carlos García Gómez            <carlos@facturascripts.com>
 * @collaborator Jerónimo Pedro Sánchez Manzano <socger@gmail.com>
 */
class StockMovementManager
{
    /** @var string[] pila de lotes abiertos */
    private static $batchStack = [];

    /** @var array documentos cacheados durante una ventana de caché */
    private static $cacheDocuments = [];

    /** @var array líneas de documento cacheadas durante una ventana de caché */
    private static $cacheDocLines = [];

    /** @var bool indica si la caché de documentos y transformaciones está activa */
    private static $cacheEnabled = false;

    /** @var array documentos de línea cacheados durante una ventana de caché */
    private static $cacheLineDocuments = [];

    /** @var array transformaciones en las que el documento es el hijo, indexadas por idlinea2 */
    private static $cacheTransAsChild = [];

    /** @var array transformaciones en las que el documento es el padre, indexadas por idlinea1 */
    private static $cacheTransAsParent = [];

    /** @var bool indica si el cálculo de saldos está aplazado */
    private static $deferSaldos = false;

    /** @var int|null */
    private static $idproducto = null;

    /** @var StockMovementModInterface[] */
    private static $mods = [];

    /** @var array pares (documento, referencia) pendientes de procesar al cerrar el lote */
    private static $pendingDocRefs = [];

    /** @var array productos pendientes de notificar al cerrar el lote */
    private static $pendingProducts = [];

    /** @var array */
    private static $pendingSaldos = [];

    /** @var array */
    private static $products = [];

    /** @var bool */
    private static $rebuilding = false;

    /** @var bool indica si ya hemos registrado la función de cierre de PHP */
    private static $shutdownRegistered = false;

    /** @var array */
    private static $variants = [];

    /**
     * Crea o actualiza el movimiento de stock de una línea de documento.
     * Si hay un lote abierto, sólo anota el par (documento, referencia) para procesarlo al cerrarlo.
     *
     * @param BusinessDocumentLine $line
     * @param TransformerDocument $doc
     */
    public static function addLineBusinessDocument(BusinessDocumentLine $line, TransformerDocument $doc): void
    {
        if (empty($line->referencia)) {
            return;
        }

        // si hay un lote abierto, aplazamos el cálculo hasta cerrarlo
        if (static::inBatch()) {
            $key = $doc->modelClassName() . '|' . $doc->id() . '|' . $line->referencia;
            static::$pendingDocRefs[$key] = [
                'codalmacen' => $doc->codalmacen,
                'docid' => $doc->id(),
                'docmodel' => $doc->modelClassName(),
                'referencia' => $line->referencia,
            ];
            static::queueProductUpdate($line->idproducto);
            return;
        }

        $changed = false;
        $opened = static::openCache();
        try {
            if (false === static::mustCreateBusinessDocumentMovement($line)) {
                return;
            }

            $movement = static::getBusinessDocumentMovement($line, $doc);
            $cantidad = static::getBusinessDocumentMovementQuantityForReference($line, $doc);

            // ¿cambia algo que afecte al saldo acumulado?
            $existed = $movement->exists();
            $changed = false === $existed
                || $movement->cantidad != $cantidad
                || $movement->fecha !== $doc->fecha
                || $movement->hora !== $doc->hora;

            $saldo = $movement->saldo;
            $movement->cantidad = $cantidad;
            $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . $doc->codigo;
            $movement->fecha = $doc->fecha;
            $movement->hora = $doc->hora;
            $movement->saldo = $changed ? 0 : $saldo;

            if (empty($movement->cantidad) && false === static::shouldSaveZeroBusinessDocumentMovement($line)) {
                $movement->delete();
                $changed = $existed;
                return;
            }

            $movement->save();
        } finally {
            if ($opened) {
                static::closeCache();
            }
            if ($changed) {
                // sólo hay que recalcular desde la fecha del documento en adelante
                static::updateReferenceSaldos($doc->codalmacen, $line->referencia, $doc->fecha);
            }
        }
    }

    public static function addLineCounting(LineaConteoStock $line, ConteoStock $stockCount, float $stock): bool
    {
        $movement = static::findMovement($stockCount->codalmacen, $stockCount, $line->referencia);
        if (false === $movement->exists()) {
            // no existe, lo creamos
            $movement->documento = Tools::trans($stockCount->modelClassName()) . ' ' . $stockCount->id();
            $movement->fecha = Tools::date($stockCount->fechafin);
            $movement->hora = Tools::hour($stockCount->fechafin);
            $movement->codalmacen = $stockCount->codalmacen;
            $movement->docid = $stockCount->id();
            $movement->docmodel = $stockCount->modelClassName();
            $movement->idproducto = $line->idproducto;
            $movement->referencia = $line->referencia;
        }

        // actualizamos la cantidad y el saldo
        $movement->cantidad = 0;
        $movement->saldo = $stock;

        if (false === $movement->save()) {
            return false;
        }

        static::updateReferenceSaldos($stockCount->codalmacen, $line->referencia);
        return true;
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
        $movement = static::findMovement($fromCodalmacen, $doc, $line->referencia);
        if ($movement->exists()) {
            $movement->codalmacen = $toCodalmacen;
            $movement->save();
        }
    }

    /**
     * Abre un lote. Mientras haya lotes abiertos, los movimientos de documentos se aplazan.
     *
     * @param string $owner identificador del propietario del lote
     */
    public static function beginBatch(string $owner): void
    {
        // si no hay lotes abiertos pero quedó trabajo pendiente de un ciclo anterior
        // que terminó mal, lo procesamos ahora
        if (empty(static::$batchStack) && (static::$pendingDocRefs || static::$pendingProducts)) {
            static::processBatch();
        }

        static::$batchStack[] = $owner;

        // registramos una válvula de seguridad, por si el proceso termina con lotes abiertos
        if (false === static::$shutdownRegistered) {
            static::$shutdownRegistered = true;
            register_shutdown_function([static::class, 'shutdownFlush']);
        }
    }

    public static function cleanResidualMovements(TransformerDocument $doc): void
    {
        // recorremos los movimientos asociados a este documento
        $where = [
            Where::eq('docid', $doc->id()),
            Where::eq('docmodel', $doc->modelClassName()),
        ];
        $movements = MovimientoStock::all($where, [], 0, 0);
        if (empty($movements)) {
            return;
        }

        // dentro de un lote basta con anotar los pares: al cerrarlo se comprueba
        // si alguna línea sigue justificando cada movimiento
        if (static::inBatch()) {
            foreach ($movements as $movement) {
                $key = $doc->modelClassName() . '|' . $doc->id() . '|' . $movement->referencia;
                static::$pendingDocRefs[$key] = [
                    'codalmacen' => $movement->codalmacen,
                    'docid' => $doc->id(),
                    'docmodel' => $doc->modelClassName(),
                    'referencia' => $movement->referencia,
                ];
            }
            return;
        }

        $pairs = [];
        $opened = static::openCache();
        try {
            static::preloadDocTransformations($doc->modelClassName(), $doc->id());

            // agrupamos las líneas por referencia para no consultarlas dentro del bucle
            $linesByRef = [];
            foreach (static::getDocLines($doc) as $line) {
                $linesByRef[$line->referencia][] = $line;
            }

            foreach ($movements as $movement) {
                // ¿alguna línea del documento sigue justificando el movimiento?
                $stillValid = false;
                foreach ($linesByRef[$movement->referencia] ?? [] as $line) {
                    if (static::mustCreateBusinessDocumentMovement($line)) {
                        $stillValid = true;
                        break;
                    }
                }
                if ($stillValid) {
                    continue;
                }

                $movement->delete();

                $key = $movement->codalmacen . '|' . $movement->referencia;
                $fromFecha = $movement->fecha;
                if (isset($pairs[$key]) && strtotime($pairs[$key][2]) < strtotime($fromFecha)) {
                    $fromFecha = $pairs[$key][2];
                }
                $pairs[$key] = [$movement->codalmacen, $movement->referencia, $fromFecha];
            }
        } finally {
            if ($opened) {
                static::closeCache();
            }
        }

        foreach ($pairs as [$codalmacen, $referencia, $fromFecha]) {
            static::updateReferenceSaldos($codalmacen, $referencia, $fromFecha);
        }
    }

    public static function deleteLineCounting(LineaConteoStock $line, ConteoStock $stockCount): bool
    {
        $movement = static::findMovement($stockCount->codalmacen, $stockCount, $line->referencia);
        if (false === $movement->exists()) {
            return true;
        }

        if (false === $movement->delete()) {
            return false;
        }

        static::updateReferenceSaldos($stockCount->codalmacen, $line->referencia);
        return true;
    }

    public static function deleteLineTransfer(LineaTransferenciaStock $line, TransferenciaStock $stockCount): bool
    {
        if (false === static::deleteLineTransferMovement($stockCount->codalmacenorigen, $line, $stockCount)) {
            return false;
        }

        return static::deleteLineTransferMovement($stockCount->codalmacendestino, $line, $stockCount);
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

    /**
     * Cierra el lote del propietario indicado. Si no quedan lotes abiertos, procesa lo pendiente.
     *
     * @param string $owner identificador del propietario del lote
     */
    public static function flushBatch(string $owner): void
    {
        // si este propietario no tiene ningún lote abierto, no hacemos nada
        if (false === in_array($owner, static::$batchStack, true)) {
            return;
        }

        // desapilamos hasta el propio lote, descartando los lotes huérfanos que hubiera encima
        while (static::$batchStack) {
            $top = array_pop(static::$batchStack);
            if ($top === $owner) {
                break;
            }
        }

        if (static::$batchStack) {
            // seguimos dentro de un lote exterior
            return;
        }

        static::processBatch();
    }

    public static function inBatch(): bool
    {
        return !empty(static::$batchStack);
    }

    /**
     * Anota un producto para enviar un único evento de actualización al cerrar el lote.
     *
     * @param int|null $idproducto
     */
    public static function queueProductUpdate(?int $idproducto): void
    {
        if (!empty($idproducto)) {
            static::$pendingProducts[$idproducto] = $idproducto;
        }
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

        // activamos el modo rebuild para aplazar el recálculo de saldos
        static::$rebuilding = true;
        static::$pendingSaldos = [];

        try {
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
        } finally {
            // recalculamos los saldos una sola vez por (almacén, referencia)
            $pending = static::$pendingSaldos;
            static::$pendingSaldos = [];
            static::$rebuilding = false;
            foreach ($pending as [$codalmacen, $referencia, $fromFecha]) {
                static::updateReferenceSaldos($codalmacen, $referencia, $fromFecha);
            }
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

    /**
     * Válvula de seguridad: procesa lo pendiente aunque queden lotes abiertos.
     */
    public static function shutdownFlush(): void
    {
        static::$batchStack = [];
        static::processBatch();
    }

    public static function updateDocumentDate(TransformerDocument $doc): void
    {
        // si no han cambiado fecha ni hora, no hacemos nada
        if ($doc->getOriginal('fecha') === $doc->fecha && $doc->getOriginal('hora') === $doc->hora) {
            return;
        }

        $where = [
            Where::eq('docid', $doc->id()),
            Where::eq('docmodel', $doc->modelClassName()),
        ];
        $pairs = [];
        foreach (MovimientoStock::all($where, [], 0, 0) as $movement) {
            // hay que recalcular desde la más antigua de las dos fechas
            $fromFecha = strtotime($movement->fecha) < strtotime($doc->fecha) ? $movement->fecha : $doc->fecha;

            $movement->fecha = $doc->fecha;
            $movement->hora = $doc->hora;
            $movement->save();

            $key = $movement->codalmacen . '|' . $movement->referencia;
            if (isset($pairs[$key]) && strtotime($pairs[$key][2]) < strtotime($fromFecha)) {
                $fromFecha = $pairs[$key][2];
            }
            $pairs[$key] = [$movement->codalmacen, $movement->referencia, $fromFecha];
        }

        // recalculamos saldos de los pares (almacén, referencia) afectados
        foreach ($pairs as [$codalmacen, $referencia, $fromFecha]) {
            static::updateReferenceSaldos($codalmacen, $referencia, $fromFecha);
        }
    }

    /**
     * Recalcula el saldo acumulado de los movimientos de una referencia en un almacén.
     *
     * @param string $codalmacen
     * @param string $referencia
     * @param string|null $fromFecha si se indica, sólo se recalcula desde esa fecha en adelante,
     *                               partiendo del saldo del último movimiento anterior.
     */
    public static function updateReferenceSaldos(string $codalmacen, string $referencia, ?string $fromFecha = null): void
    {
        // durante un rebuild o un lote aplazamos el recálculo y lo hacemos una sola vez por par al final
        if (static::$rebuilding || static::$deferSaldos) {
            $key = $codalmacen . '|' . $referencia;
            if (array_key_exists($key, static::$pendingSaldos)) {
                // nos quedamos con la fecha más antigua de las solicitadas
                $previous = static::$pendingSaldos[$key][2];
                if (is_null($previous)) {
                    $fromFecha = null;
                } elseif (false === is_null($fromFecha) && strtotime($previous) < strtotime($fromFecha)) {
                    $fromFecha = $previous;
                }
            }
            static::$pendingSaldos[$key] = [$codalmacen, $referencia, $fromFecha];
            return;
        }

        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('referencia', $referencia)
        ];

        // si sólo recalculamos una parte, partimos del saldo que había justo antes
        $saldo = 0.0;
        if (false === is_null($fromFecha)) {
            $saldo = static::getSaldoBeforeDate($codalmacen, $referencia, $fromFecha);
            $where[] = Where::gte('fecha', $fromFecha);
        }

        $movements = static::sortMovements(MovimientoStock::all($where, ['fecha' => 'ASC', 'hora' => 'ASC', 'id' => 'ASC'], 0, 0));

        foreach ($movements as $movement) {
            if ($movement->docmodel === 'ConteoStock') {
                $saldo = $movement->saldo;
                continue;
            }

            $saldo += $movement->cantidad;
            if ($movement->saldo != $saldo) {
                $movement->saldo = $saldo;
                $movement->save();
            }
        }
    }

    protected static function addLineTransferStockMovement(string $codalmacen, float $cantidad, TransferenciaStock $transfer, LineaTransferenciaStock $line): bool
    {
        $movement = static::findMovement($codalmacen, $transfer, $line->referencia);
        if (false === $movement->exists()) {
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

    /**
     * Devuelve la primera transformación en la que el documento indicado es el hijo de la línea dada.
     *
     * @param string $docmodel
     * @param mixed $docid
     * @param mixed $idlinea
     *
     * @return DocTransformation|null
     */
    protected static function cachedChildTransformation(string $docmodel, $docid, $idlinea): ?DocTransformation
    {
        $key = $docmodel . '|' . $docid;
        return static::$cacheTransAsChild[$key][$idlinea] ?? null;
    }

    /**
     * Devuelve las transformaciones en las que el documento indicado es el padre de la línea dada.
     *
     * @param string $docmodel
     * @param mixed $docid
     * @param mixed $idlinea
     *
     * @return DocTransformation[]
     */
    protected static function cachedParentTransformations(string $docmodel, $docid, $idlinea): array
    {
        $key = $docmodel . '|' . $docid;
        return static::$cacheTransAsParent[$key][$idlinea] ?? [];
    }

    /**
     * Desactiva y vacía la caché de documentos y transformaciones.
     */
    protected static function closeCache(): void
    {
        static::$cacheEnabled = false;
        static::$cacheDocuments = [];
        static::$cacheDocLines = [];
        static::$cacheLineDocuments = [];
        static::$cacheTransAsChild = [];
        static::$cacheTransAsParent = [];
    }

    protected static function deleteLineTransferMovement(string $codalmacen, LineaTransferenciaStock $line, TransferenciaStock $stockCount): bool
    {
        $movement = static::findMovement($codalmacen, $stockCount, $line->referencia);
        if ($movement->exists() && false === $movement->delete()) {
            return false;
        }

        return true;
    }

    protected static function findMovement(string $codalmacen, $doc, string $referencia): MovimientoStock
    {
        $movement = new MovimientoStock();
        $movement->loadWhere([
            Where::eq('codalmacen', $codalmacen),
            Where::eq('docid', $doc->id()),
            Where::eq('docmodel', $doc->modelClassName()),
            Where::eq('referencia', $referencia),
        ]);
        return $movement;
    }

    protected static function getBusinessDocumentMovement(BusinessDocumentLine $line, TransformerDocument $doc): MovimientoStock
    {
        $movement = static::findMovement($doc->codalmacen, $doc, $line->referencia);
        if ($movement->exists()) {
            return $movement;
        }

        $movement->codalmacen = $doc->codalmacen;
        $movement->docid = $doc->id();
        $movement->docmodel = $doc->modelClassName();
        $movement->idproducto = $line->idproducto ?? $line->getProducto()->idproducto;
        $movement->referencia = $line->referencia;
        return $movement;
    }

    protected static function getBusinessDocumentMovementQuantity(BusinessDocumentLine $line): float
    {
        $transformation = static::getTransformationFromChildLine($line);
        if (false === is_null($transformation)) {
            return $line->exists() ?
                static::getCurrentBusinessDocumentLineQuantity($line) - static::getSignedTransformationQuantity($transformation) :
                0.0;
        }

        return static::getCurrentBusinessDocumentLineQuantity($line) + static::getSignedChildrenTransformationQuantity($line);
    }

    protected static function getBusinessDocumentMovementQuantityForReference(BusinessDocumentLine $line, TransformerDocument $doc): float
    {
        // El hook se dispara antes de persistir la línea, así que $line puede no estar
        // todavía en $doc->getLines(). Tratamos $line como autoritativa para su propia
        // contribución y sumamos las del resto de líneas con la misma referencia.
        $linePk = $line->id();
        $quantity = static::getBusinessDocumentMovementQuantity($line);
        foreach (static::getDocLines($doc) as $docLine) {
            if ($docLine->referencia !== $line->referencia) {
                continue;
            }
            if (!empty($linePk) && $docLine->id() == $linePk) {
                continue;
            }
            $quantity += static::getBusinessDocumentMovementQuantity($docLine);
        }
        return $quantity;
    }

    protected static function getCurrentBusinessDocumentLineQuantity(BusinessDocumentLine $line): float
    {
        return in_array($line->actualizastock, [-1, 1], true) ?
            $line->actualizastock * ((float)$line->cantidad - (float)$line->servido) :
            0.0;
    }

    /**
     * Devuelve las líneas de un documento, cacheadas si la caché está activa.
     *
     * @param TransformerDocument $doc
     *
     * @return BusinessDocumentLine[]
     */
    protected static function getDocLines(TransformerDocument $doc): array
    {
        if (false === static::$cacheEnabled) {
            return $doc->getLines();
        }

        $key = $doc->modelClassName() . '|' . $doc->id();
        if (false === isset(static::$cacheDocLines[$key])) {
            static::$cacheDocLines[$key] = $doc->getLines();
        }

        return static::$cacheDocLines[$key];
    }

    /**
     * Devuelve el documento de una línea, cacheado si la caché está activa.
     * Sólo debe usarse para leer, nunca para modificar el documento.
     *
     * @param BusinessDocumentLine $line
     *
     * @return TransformerDocument|mixed
     */
    protected static function getLineDocument(BusinessDocumentLine $line)
    {
        if (false === static::$cacheEnabled) {
            return $line->getDocument();
        }

        $key = get_class($line) . '|' . $line->documentColumnValue();
        if (false === isset(static::$cacheLineDocuments[$key])) {
            static::$cacheLineDocuments[$key] = $line->getDocument();
        }

        return static::$cacheLineDocuments[$key];
    }

    protected static function getMovementSortPriority(string $docmodel): int
    {
        switch ($docmodel) {
            case 'AlbaranCliente':
            case 'AlbaranProveedor':
                return 10;

            case 'TransferenciaStock':
                return 20;

            case 'ConteoStock':
                return 30;

            case 'FacturaCliente':
            case 'FacturaProveedor':
                return 40;
        }

        return 50;
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
            if (strtotime($movement->fecha . ' ' . $movement->hora) < strtotime($fecha . ' ' . $hora)) {
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

    protected static function getSignedChildrenTransformationQuantity(BusinessDocumentLine $line): float
    {
        $doc = static::getLineDocument($line);
        if (empty($doc->id()) || empty($line->id())) {
            return 0.0;
        }

        $quantity = 0.0;
        foreach (static::getTransformationsAsParent($doc, $line) as $transformation) {
            if (false === static::isSupportedStockTransformation($transformation)) {
                continue;
            }

            $quantity += static::getSignedTransformationQuantity($transformation);
        }

        return $quantity;
    }

    protected static function getSignedTransformationQuantity(DocTransformation $transformation): float
    {
        return static::getTransformationStockMode($transformation) * (float)$transformation->cantidad;
    }

    protected static function getTransformationFromChildLine(BusinessDocumentLine $line): ?DocTransformation
    {
        $doc = static::getLineDocument($line);
        if (empty($doc->id()) || empty($line->id())) {
            return null;
        }

        if (static::$cacheEnabled) {
            static::preloadDocTransformations($doc->modelClassName(), $line->documentColumnValue());
            $transformation = static::cachedChildTransformation($doc->modelClassName(), $line->documentColumnValue(), $line->id());
            if (is_null($transformation) || false === static::isSupportedStockTransformation($transformation)) {
                return null;
            }

            return $transformation;
        }

        $where = [
            Where::eq('model2', $doc->modelClassName()),
            Where::eq('iddoc2', $line->documentColumnValue()),
            Where::eq('idlinea2', $line->id())
        ];
        foreach (DocTransformation::all($where, ['id' => 'ASC'], 0, 1) as $transformation) {
            if (static::isSupportedStockTransformation($transformation)) {
                return $transformation;
            }
        }

        return null;
    }

    protected static function getTransformationStockMode(DocTransformation $transformation): int
    {
        switch ($transformation->model2) {
            case 'FacturaCliente':
                return -1;

            case 'FacturaProveedor':
                return 1;
        }

        return 0;
    }

    /**
     * Devuelve las transformaciones en las que la línea es el origen.
     *
     * @param mixed $doc
     * @param BusinessDocumentLine $line
     *
     * @return DocTransformation[]
     */
    protected static function getTransformationsAsParent($doc, BusinessDocumentLine $line): array
    {
        if (static::$cacheEnabled) {
            static::preloadDocTransformations($doc->modelClassName(), $line->documentColumnValue());
            return static::cachedParentTransformations($doc->modelClassName(), $line->documentColumnValue(), $line->id());
        }

        $where = [
            Where::eq('model1', $doc->modelClassName()),
            Where::eq('iddoc1', $line->documentColumnValue()),
            Where::eq('idlinea1', $line->id())
        ];
        return DocTransformation::all($where, ['id' => 'ASC'], 0, 0);
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

    protected static function isSupportedStockTransformation(DocTransformation $transformation): bool
    {
        return ($transformation->model1 === 'AlbaranCliente' && $transformation->model2 === 'FacturaCliente') ||
            ($transformation->model1 === 'AlbaranProveedor' && $transformation->model2 === 'FacturaProveedor');
    }

    /**
     * Carga un documento a partir de su modelo e identificador.
     *
     * @param string $docmodel
     * @param mixed $docid
     *
     * @return TransformerDocument|null
     */
    protected static function loadDocument(string $docmodel, $docid)
    {
        $key = $docmodel . '|' . $docid;
        if (static::$cacheEnabled && array_key_exists($key, static::$cacheDocuments)) {
            return static::$cacheDocuments[$key];
        }

        $className = '\\FacturaScripts\\Dinamic\\Model\\' . $docmodel;
        if (false === class_exists($className)) {
            return null;
        }

        $doc = new $className();
        $result = ($doc instanceof TransformerDocument) && $doc->load($docid) ? $doc : null;

        if (static::$cacheEnabled) {
            static::$cacheDocuments[$key] = $result;
        }

        return $result;
    }

    protected static function mustCreateBusinessDocumentMovement(BusinessDocumentLine $line): bool
    {
        if (in_array($line->actualizastock, [-1, 1], true) || in_array($line->getOriginal('actualizastock'), [-1, 1], true)) {
            return true;
        }

        if (false === is_null(static::getTransformationFromChildLine($line))) {
            return true;
        }

        return !empty(static::getSignedChildrenTransformationQuantity($line));
    }

    /**
     * Activa y vacía la caché de documentos y transformaciones.
     *
     * @return bool True si esta llamada ha abierto la caché y debe cerrarla.
     */
    protected static function openCache(): bool
    {
        if (static::$cacheEnabled) {
            return false;
        }

        static::closeCache();
        static::$cacheEnabled = true;
        return true;
    }

    /**
     * Carga en memoria, con sólo dos consultas, todas las transformaciones de un documento.
     *
     * @param string $docmodel
     * @param mixed $docid
     */
    protected static function preloadDocTransformations(string $docmodel, $docid): void
    {
        $key = $docmodel . '|' . $docid;
        if (isset(static::$cacheTransAsChild[$key])) {
            return;
        }

        static::$cacheTransAsChild[$key] = [];
        static::$cacheTransAsParent[$key] = [];

        if (empty($docid)) {
            return;
        }

        // transformaciones en las que este documento es el hijo
        $where = [
            Where::eq('model2', $docmodel),
            Where::eq('iddoc2', $docid),
        ];
        foreach (DocTransformation::all($where, ['id' => 'ASC'], 0, 0) as $transformation) {
            // sólo nos interesa la primera de cada línea, igual que la consulta original
            if (false === isset(static::$cacheTransAsChild[$key][$transformation->idlinea2])) {
                static::$cacheTransAsChild[$key][$transformation->idlinea2] = $transformation;
            }
        }

        // transformaciones en las que este documento es el padre
        $where2 = [
            Where::eq('model1', $docmodel),
            Where::eq('iddoc1', $docid),
        ];
        foreach (DocTransformation::all($where2, ['id' => 'ASC'], 0, 0) as $transformation) {
            static::$cacheTransAsParent[$key][$transformation->idlinea1][] = $transformation;
        }
    }

    /**
     * Procesa todo lo pendiente de un lote: calcula una sola vez cada movimiento
     * y una sola vez el saldo de cada (almacén, referencia).
     */
    protected static function processBatch(): void
    {
        $pendingRefs = static::$pendingDocRefs;
        $pendingProducts = static::$pendingProducts;
        static::$pendingDocRefs = [];
        static::$pendingProducts = [];

        if (empty($pendingRefs) && empty($pendingProducts)) {
            return;
        }

        if ($pendingRefs) {
            $opened = static::openCache();
            static::$deferSaldos = true;
            try {
                foreach ($pendingRefs as $item) {
                    static::rebuildDocumentReferenceMovement(
                        $item['docmodel'], $item['docid'], $item['referencia'], $item['codalmacen']
                    );
                }
            } finally {
                static::$deferSaldos = false;
                if ($opened) {
                    static::closeCache();
                }

                $pendingSaldos = static::$pendingSaldos;
                static::$pendingSaldos = [];
                foreach ($pendingSaldos as [$codalmacen, $referencia, $fromFecha]) {
                    static::updateReferenceSaldos($codalmacen, $referencia, $fromFecha);
                }
            }
        }

        // enviamos un único evento por producto
        foreach ($pendingProducts as $idproducto) {
            WorkQueue::send('Model.Producto.updateStockMovements', $idproducto);
        }
    }

    /**
     * Crea, actualiza o elimina el movimiento de stock de un documento y una referencia,
     * a partir del estado actual de la base de datos.
     *
     * @param string $docmodel
     * @param mixed $docid
     * @param string $referencia
     * @param string $oldCodalmacen almacén con el que se anotó el movimiento en el lote
     */
    protected static function rebuildDocumentReferenceMovement(string $docmodel, $docid, string $referencia, string $oldCodalmacen): void
    {
        $doc = static::loadDocument($docmodel, $docid);

        // el documento ya no existe, eliminamos sus movimientos
        if (is_null($doc)) {
            $where = [
                Where::eq('docid', $docid),
                Where::eq('docmodel', $docmodel),
                Where::eq('referencia', $referencia),
            ];
            foreach (MovimientoStock::all($where, [], 0, 0) as $movement) {
                $movement->delete();
                static::updateReferenceSaldos($movement->codalmacen, $referencia, $movement->fecha);
            }
            return;
        }

        // si el documento ha cambiado de almacén, hay que recalcular también el anterior
        if ($oldCodalmacen !== $doc->codalmacen) {
            static::updateReferenceSaldos($oldCodalmacen, $referencia, $doc->fecha);
        }

        static::preloadDocTransformations($docmodel, $docid);

        // líneas del documento con esta referencia
        $lines = [];
        foreach (static::getDocLines($doc) as $line) {
            if ($line->referencia === $referencia) {
                $lines[] = $line;
            }
        }

        $movement = static::findMovement($doc->codalmacen, $doc, $referencia);

        // ¿alguna línea justifica el movimiento?
        $mustCreate = false;
        foreach ($lines as $line) {
            if (static::mustCreateBusinessDocumentMovement($line)) {
                $mustCreate = true;
                break;
            }
        }
        if (false === $mustCreate) {
            if ($movement->exists()) {
                $movement->delete();
            }
            static::updateReferenceSaldos($doc->codalmacen, $referencia, $doc->fecha);
            return;
        }

        // sumamos la contribución de todas las líneas con esta referencia
        $cantidad = 0.0;
        $keepZero = false;
        foreach ($lines as $line) {
            $cantidad += static::getBusinessDocumentMovementQuantity($line);
            $keepZero = $keepZero || static::shouldSaveZeroBusinessDocumentMovement($line);
        }

        if (false === $movement->exists()) {
            $first = $lines[0];
            $movement->codalmacen = $doc->codalmacen;
            $movement->docid = $doc->id();
            $movement->docmodel = $doc->modelClassName();
            $movement->idproducto = $first->idproducto ?? $first->getProducto()->idproducto;
            $movement->referencia = $referencia;
        }

        // ¿cambia algo que afecte al saldo acumulado?
        $existed = $movement->exists();
        $saldoChanged = false === $existed
            || $movement->cantidad != $cantidad
            || $movement->fecha !== $doc->fecha
            || $movement->hora !== $doc->hora;

        $saldo = $movement->saldo;
        $movement->cantidad = $cantidad;
        $movement->documento = Tools::trans($doc->modelClassName()) . ' ' . $doc->codigo;
        $movement->fecha = $doc->fecha;
        $movement->hora = $doc->hora;
        $movement->saldo = $saldoChanged ? 0 : $saldo;

        if (empty($cantidad) && false === $keepZero) {
            $movement->delete();
            $saldoChanged = $existed;
        } else {
            $movement->save();
        }

        // si el movimiento no ha cambiado, el saldo de los posteriores sigue siendo válido
        if ($saldoChanged) {
            static::updateReferenceSaldos($doc->codalmacen, $referencia, $doc->fecha);
        }
    }

    protected static function rebuildBusinessDocument(): void
    {
        $limit = 250;
        $models = [
            new LineaAlbaranProveedor(),
            new LineaFacturaProveedor(),
            new LineaAlbaranCliente(),
            new LineaFacturaCliente()
        ];
        foreach ($models as $model) {
            $where = [
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

                    static::addLineBusinessDocument($line, $doc);
                }

                $offset += $limit;
                $lines = $model->all($where, ['idlinea' => 'ASC'], $offset, $limit);
            }
        }
    }

    protected static function rebuildStockCounting(): void
    {
        // recorremos todas las líneas de conteo de stock
        foreach (LineaConteoStock::allWhereEq('idproducto', static::$idproducto, ['idlinea' => 'ASC']) as $line) {
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
        foreach (LineaTransferenciaStock::allWhereEq('idproducto', static::$idproducto, ['idlinea' => 'ASC']) as $line) {
            // si la transferencia no está finalizada, la omitimos
            $transfer = $line->getTransference();
            if (!$transfer->completed) {
                continue;
            }

            static::addLineTransferStock($line, $transfer);
        }
    }

    /**
     * Devuelve el saldo acumulado de la referencia justo antes de la fecha indicada.
     *
     * @param string $codalmacen
     * @param string $referencia
     * @param string $fecha
     *
     * @return float
     */
    protected static function getSaldoBeforeDate(string $codalmacen, string $referencia, string $fecha): float
    {
        // buscamos la fecha del último movimiento anterior
        $where = [
            Where::eq('codalmacen', $codalmacen),
            Where::eq('referencia', $referencia),
            Where::lt('fecha', $fecha)
        ];
        $previous = MovimientoStock::all($where, ['fecha' => 'DESC'], 0, 1);
        if (empty($previous)) {
            return 0.0;
        }

        // cargamos todos los movimientos de ese día y tomamos el saldo del último,
        // aplicando el mismo criterio de ordenación que usamos para recalcular
        $where[2] = Where::eq('fecha', $previous[0]->fecha);
        $movements = static::sortMovements(MovimientoStock::all($where, ['fecha' => 'ASC', 'hora' => 'ASC', 'id' => 'ASC'], 0, 0));

        return (float)end($movements)->saldo;
    }

    /**
     * Ordena los movimientos por fecha, hora, tipo de documento e id.
     *
     * @param MovimientoStock[] $movements
     *
     * @return MovimientoStock[]
     */
    protected static function sortMovements(array $movements): array
    {
        usort($movements, function (MovimientoStock $a, MovimientoStock $b) {
            $dateA = strtotime($a->fecha . ' ' . $a->hora);
            $dateB = strtotime($b->fecha . ' ' . $b->hora);
            if ($dateA !== $dateB) {
                return $dateA <=> $dateB;
            }

            $priority = static::getMovementSortPriority($a->docmodel) <=> static::getMovementSortPriority($b->docmodel);
            if ($priority !== 0) {
                return $priority;
            }

            return $a->id() <=> $b->id();
        });

        return $movements;
    }

    protected static function shouldSaveZeroBusinessDocumentMovement(BusinessDocumentLine $line): bool
    {
        if (false === $line->exists()) {
            return false;
        }

        return false === is_null(static::getTransformationFromChildLine($line));
    }
}
