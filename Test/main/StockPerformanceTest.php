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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockRebuildManager;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\LogMessage;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests de rendimiento al facturar y agrupar albaranes. Miden el tiempo de la operación
 * síncrona; los saldos se comprueban tras ejecutar la cola de trabajos.
 * Basados en los tests de la PR #50.
 */
final class StockPerformanceTest extends TestCase
{
    use CleanTestDataTrait;
    use LogErrorsTrait;
    use RandomDataTrait;
    use DefaultSettingsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
        self::saveLastIds();
    }

    public static function tearDownAfterClass(): void
    {
        self::deleteNewRecords();
    }

    public function testPerformanceAlbaranProveedor200LineasMismaReferencia(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos 200 líneas con la misma referencia
        $numLines = 200;
        for ($num = 0; $num < $numLines; $num++) {
            $linea = $albaran->getNewProductLine($product->referencia);
            $linea->cantidad = 1;
            $linea->pvpunitario = 1;
            $this->assertTrue($linea->save());
        }

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId);

        // medimos el tiempo que tarda en pasar a factura
        $start = microtime(true);
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $elapsed = microtime(true) - $start;
        $queueElapsed = $this->runWorkQueue();

        fwrite(STDERR, PHP_EOL . '200 líneas misma referencia -> factura: ' . round($elapsed, 2) . ' s (cola: ' . round($queueElapsed, 2) . ' s)' . PHP_EOL);

        // comprobamos que la factura se ha creado
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);

        // sólo debe haber 2 movimientos: el del albarán y el de la factura
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertCount(2, MovimientoStock::all($whereRef, [], 0, 0));

        $deliveryMovement = new MovimientoStock();
        $this->assertTrue($deliveryMovement->loadWhere(array_merge($whereRef, [
            Where::eq('docid', $albaran->id()),
            Where::eq('docmodel', $albaran->modelClassName())
        ])));
        $this->assertEquals($numLines, $deliveryMovement->cantidad);
        $this->assertEquals($numLines, $deliveryMovement->saldo);

        $invoiceMovement = new MovimientoStock();
        $this->assertTrue($invoiceMovement->loadWhere(array_merge($whereRef, [
            Where::eq('docid', $facturas[0]->id()),
            Where::eq('docmodel', $facturas[0]->modelClassName())
        ])));
        $this->assertEquals(0, $invoiceMovement->cantidad);
        $this->assertEquals($numLines, $invoiceMovement->saldo);

        // el proceso debe ser rápido
        $this->assertLessThan(self::perfBudget(25.0), $elapsed, 'el paso a factura es demasiado lento');

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testPerformanceAlbaranProveedor50Productos(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos 50 productos
        $numProducts = 50;
        $products = [];
        for ($num = 0; $num < $numProducts; $num++) {
            $product = $this->getRandomProduct();
            $this->assertTrue($product->save());
            $products[] = $product;

        }

        // creamos el albarán con una línea por producto
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        foreach ($products as $product) {
            $linea = $albaran->getNewProductLine($product->referencia);
            $linea->cantidad = 1;
            $linea->pvpunitario = 1;
            $this->assertTrue($linea->save());
        }

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaran->getAvailableStatus() as $status) {
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId);

        // medimos el tiempo que tarda en pasar a factura
        $start = microtime(true);
        $albaran->idestado = $invoiceStatusId;
        $this->assertTrue($albaran->save());
        $elapsed = microtime(true) - $start;
        $queueElapsed = $this->runWorkQueue();

        fwrite(STDERR, PHP_EOL . '50 productos -> factura: ' . round($elapsed, 2) . ' s (cola: ' . round($queueElapsed, 2) . ' s)' . PHP_EOL);

        // comprobamos que la factura se ha creado
        $facturas = $albaran->childrenDocuments();
        $this->assertCount(1, $facturas);

        // comprobamos los movimientos de cada producto
        foreach ($products as $product) {
            $whereRef = [
                Where::eq('codalmacen', $warehouse->codalmacen),
                Where::eq('referencia', $product->referencia)
            ];
            $this->assertCount(2, MovimientoStock::all($whereRef, [], 0, 0));

            $deliveryMovement = new MovimientoStock();
            $this->assertTrue($deliveryMovement->loadWhere(array_merge($whereRef, [
                Where::eq('docid', $albaran->id()),
                Where::eq('docmodel', $albaran->modelClassName())
            ])));
            $this->assertEquals(1, $deliveryMovement->cantidad);
            $this->assertEquals(1, $deliveryMovement->saldo);

            $invoiceMovement = new MovimientoStock();
            $this->assertTrue($invoiceMovement->loadWhere(array_merge($whereRef, [
                Where::eq('docid', $facturas[0]->id()),
                Where::eq('docmodel', $facturas[0]->modelClassName())
            ])));
            $this->assertEquals(0, $invoiceMovement->cantidad);
            $this->assertEquals(1, $invoiceMovement->saldo);
        }

        // el proceso debe ser rápido
        $this->assertLessThan(self::perfBudget(30.0), $elapsed, 'el paso a factura es demasiado lento');

        // eliminamos
        $this->assertTrue($facturas[0]->delete());
        $this->assertTrue($albaran->delete());

        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        foreach ($products as $product) {
            $this->assertTrue($product->delete());
        }
        $this->assertTrue($warehouse->delete());
    }

    public function testPerformanceAlbaranProveedor50ProductosConHistorico(): void
    {
        // semilla fija para que el test sea reproducible
        mt_srand(20261020);

        // nos aseguramos de que la tabla de logs existe: al borrar la factura se escribe
        // el log de auditoría dentro de una transacción, y ahí no se puede crear la tabla
        new LogMessage();

        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos 50 productos, cada uno con entre 200 y 1000 movimientos previos
        $numProducts = 50;
        $products = [];
        $history = [];
        for ($num = 0; $num < $numProducts; $num++) {
            $product = $this->getRandomProduct();
            $this->assertTrue($product->save());
            $products[] = $product;
            $history[$product->referencia] = mt_rand(200, 1000);
        }

        // creamos el histórico: un albarán por cada movimiento previo
        $start = microtime(true);
        $historyIds = $this->createSupplierHistory($proveedor, $warehouse->codalmacen, $products, $history);
        $historyElapsed = microtime(true) - $start;

        try {
            // creamos el albarán con una línea por producto
            $albaran = new AlbaranProveedor();
            $this->assertTrue($albaran->setSubject($proveedor));
            $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
            $this->assertTrue($albaran->save());

            foreach ($products as $product) {
                $linea = $albaran->getNewProductLine($product->referencia);
                $linea->cantidad = 1;
                $linea->pvpunitario = 1;
                $this->assertTrue($linea->save());
            }

            // buscamos el estado que genera factura
            $invoiceStatusId = null;
            foreach ($albaran->getAvailableStatus() as $status) {
                if ('FacturaProveedor' === $status->generadoc) {
                    $invoiceStatusId = $status->idestado;
                    break;
                }
            }
            $this->assertNotNull($invoiceStatusId);

            // medimos el tiempo que tarda en pasar a factura
            $start = microtime(true);
            $albaran->idestado = $invoiceStatusId;
            $this->assertTrue($albaran->save());
            $elapsed = microtime(true) - $start;
            $queueElapsed = $this->runWorkQueue();

            fwrite(STDERR, PHP_EOL . '50 productos con histórico (' . array_sum($history) . ' movimientos previos) -> factura: '
                . round($elapsed, 2) . ' s (cola: ' . round($queueElapsed, 2) . ' s, histórico: '
                . round($historyElapsed, 2) . ' s)' . PHP_EOL);

            // comprobamos que la factura se ha creado
            $facturas = $albaran->childrenDocuments();
            $this->assertCount(1, $facturas);
            $factura = $facturas[0];

            // comprobamos los movimientos de cada producto
            foreach ($products as $product) {
                $total = $history[$product->referencia];
                $whereRef = [
                    Where::eq('codalmacen', $warehouse->codalmacen),
                    Where::eq('referencia', $product->referencia)
                ];

                // un movimiento por albarán del histórico + el del albarán nuevo + el de la factura
                $movements = MovimientoStock::all($whereRef, [], 0, 0);
                $this->assertCount($total + 2, $movements, 'movimientos incorrectos en ' . $product->referencia);

                // los saldos se acumulan en orden: el histórico, el albarán nuevo y la factura, que no mueve stock
                $expected = [];
                for ($pos = 0; $pos < $total; $pos++) {
                    $expected[] = ['AlbaranProveedor', $historyIds[$pos], 1, $pos + 1];
                }
                $expected[] = ['AlbaranProveedor', $albaran->id(), 1, $total + 1];
                $expected[] = ['FacturaProveedor', $factura->id(), 0, $total + 1];

                usort($movements, [StockMovementManager::class, 'compareMovements']);
                $actual = array_map(function (MovimientoStock $movement) {
                    return [$movement->docmodel, (int)$movement->docid, (float)$movement->cantidad, (float)$movement->saldo];
                }, $movements);
                $this->assertEquals($expected, $actual, 'saldos incorrectos en ' . $product->referencia);

                // el stock refleja el histórico y el albarán nuevo
                $stock = new Stock();
                $this->assertTrue($stock->loadWhere($whereRef));
                $this->assertEquals($total + 1, $stock->cantidad, 'stock incorrecto en ' . $product->referencia);
            }

            // el paso a factura sólo encola los productos, así que no depende del histórico.
            // La cola reconstruye cada producto completo y sí depende de él
            $this->assertLessThan(self::perfBudget(30.0), $elapsed, 'el paso a factura es demasiado lento');
            $this->assertLessThan(self::perfBudget(120.0), $queueElapsed, 'la reconstrucción de los movimientos es demasiado lenta');

            // eliminamos
            $this->assertTrue($factura->delete());
            $this->assertTrue($albaran->delete());
        } finally {
            $this->deleteSupplierHistory($historyIds);
        }

        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        foreach ($products as $product) {
            $this->assertTrue($product->delete());
        }
        $this->assertTrue($warehouse->delete());
    }

    public function testPerformanceAgrupar40AlbaranesProveedorEnUnaFactura(): void
    {
        // semilla fija para que el test sea reproducible
        mt_srand(20260917);

        // nos aseguramos de que la tabla de logs existe: al borrar la factura se escribe
        // el log de auditoría dentro de una transacción, y ahí no se puede crear la tabla
        new LogMessage();

        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save());

        // creamos los productos que se repetirán entre los albaranes
        $numProducts = 40;
        $products = [];
        for ($num = 0; $num < $numProducts; $num++) {
            $product = $this->getRandomProduct();
            $this->assertTrue($product->save());
            $products[] = $product;

        }

        $albaranes = [];
        $expectedQuantity = [];
        $expectedMovements = [];
        $totalLines = 0;

        // creamos 40 albaranes con entre 1 y 50 líneas cada uno
        $numAlbaranes = 40;
        for ($num = 0; $num < $numAlbaranes; $num++) {
            $albaran = new AlbaranProveedor();
            $this->assertTrue($albaran->setSubject($proveedor));
            $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
            $this->assertTrue($albaran->save());

            $numLines = mt_rand(1, 50);
            $refsInDoc = [];
            for ($pos = 0; $pos < $numLines; $pos++) {
                $product = $products[mt_rand(0, $numProducts - 1)];
                $cantidad = mt_rand(1, 5);

                $linea = $albaran->getNewProductLine($product->referencia);
                $linea->cantidad = $cantidad;
                $linea->pvpunitario = 1;
                $this->assertTrue($linea->save());

                $expectedQuantity[$product->referencia] = ($expectedQuantity[$product->referencia] ?? 0) + $cantidad;
                $refsInDoc[$product->referencia] = true;
                $totalLines++;
            }

            // cada albarán genera un movimiento por cada referencia distinta que contiene
            foreach (array_keys($refsInDoc) as $referencia) {
                $expectedMovements[$referencia] = ($expectedMovements[$referencia] ?? 0) + 1;
            }

            $albaranes[] = $albaran;
        }

        // buscamos el estado que genera factura
        $invoiceStatusId = null;
        foreach ($albaranes[0]->getAvailableStatus() as $status) {
            if ('FacturaProveedor' === $status->generadoc) {
                $invoiceStatusId = $status->idestado;
                break;
            }
        }
        $this->assertNotNull($invoiceStatusId, 'no-invoice-status-found');

        // agrupamos todos los albaranes en una sola factura,
        // reproduciendo el flujo del controlador DocumentStitcher
        $start = microtime(true);
        $newLines = [];
        $quantities = [];
        $prototype = null;

        try {
            foreach ($albaranes as $albaran) {
                $lines = $albaran->getLines();
                if (null === $prototype) {
                    $prototype = clone $albaran;
                }

                foreach ($lines as $line) {
                    $quantities[$line->id()] = (float)$line->cantidad - (float)$line->servido;
                    $newLines[] = $line;
                }

                $albaran->setDocumentGeneration(false);
                $albaran->idestado = $invoiceStatusId;
                $this->assertTrue($albaran->save());

                // reponemos las líneas con las versiones frescas, igual que DocumentStitcher
                foreach ($albaran->getLines() as $fresh) {
                    foreach ($newLines as $pos => $newLine) {
                        if ($newLine->id() === $fresh->id()) {
                            $newLines[$pos] = $fresh;
                            break;
                        }
                    }
                }
            }

            $generator = new BusinessDocumentGenerator();
            $this->assertTrue($generator->generate($prototype, 'FacturaProveedor', $newLines, $quantities));
        } finally {
            // es un flag estático, hay que restaurarlo siempre
            $albaranes[0]->setDocumentGeneration(true);
        }
        $elapsed = microtime(true) - $start;
        $queueElapsed = $this->runWorkQueue();

        fwrite(STDERR, PHP_EOL . '40 albaranes (' . $totalLines . ' líneas) -> 1 factura: '
            . round($elapsed, 2) . ' s (cola: ' . round($queueElapsed, 2) . ' s)' . PHP_EOL);

        // se ha creado una única factura con todas las líneas
        $facturas = $generator->getLastDocs();
        $this->assertCount(1, $facturas);
        $factura = $facturas[0];
        $this->assertEquals($warehouse->codalmacen, $factura->codalmacen);
        $this->assertCount($totalLines, $factura->getLines());

        // todos los albaranes apuntan a esa factura
        foreach ($albaranes as $albaran) {
            $children = $albaran->childrenDocuments();
            $this->assertCount(1, $children);
            $this->assertEquals($factura->id(), $children[0]->id());
        }

        // comprobamos los movimientos de cada producto
        foreach ($products as $product) {
            $referencia = $product->referencia;
            $whereRef = [
                Where::eq('codalmacen', $warehouse->codalmacen),
                Where::eq('referencia', $referencia)
            ];

            // si el producto no ha entrado en ningún albarán, no tiene movimientos
            if (false === isset($expectedQuantity[$referencia])) {
                $this->assertCount(0, MovimientoStock::all($whereRef, [], 0, 0));
                continue;
            }

            // un movimiento por albarán que lo contiene + el de la factura
            $this->assertCount(
                $expectedMovements[$referencia] + 1,
                MovimientoStock::all($whereRef, [], 0, 0),
                'movimientos incorrectos en ' . $referencia
            );

            // el movimiento de la factura es el delta (0) y cierra el saldo acumulado
            $invoiceMovement = new MovimientoStock();
            $this->assertTrue($invoiceMovement->loadWhere(array_merge($whereRef, [
                Where::eq('docid', $factura->id()),
                Where::eq('docmodel', $factura->modelClassName())
            ])));
            $this->assertEquals(0, $invoiceMovement->cantidad, 'la factura no debe mover stock en ' . $referencia);
            $this->assertEquals(
                $expectedQuantity[$referencia],
                $invoiceMovement->saldo,
                'saldo incorrecto en ' . $referencia
            );

            // el stock refleja todo lo recibido en los albaranes
            $stock = new Stock();
            $this->assertTrue($stock->loadWhere($whereRef));
            $this->assertEquals($expectedQuantity[$referencia], $stock->cantidad, 'stock incorrecto en ' . $referencia);
        }

        // el proceso debe ser fluido. El grueso del tiempo restante lo consume el core
        // al clonar las líneas, así que el margen es amplio y depende de la carga de la máquina
        $this->assertLessThan(self::perfBudget(120.0), $elapsed, 'la agrupación de albaranes es demasiado lenta');

        // eliminamos
        $this->assertTrue($factura->delete());
        foreach ($albaranes as $albaran) {
            $this->assertTrue($albaran->delete());
        }

        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        foreach ($products as $product) {
            $this->assertTrue($product->delete());
        }
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }

    /**
     * Crea un histórico de albaranes de proveedor de ayer, con sus movimientos y su stock. Cada albarán tiene
     * una línea de cantidad 1 por cada producto al que aún le faltan movimientos, así que cada producto
     * acaba con tantos movimientos como indica $history. El primer albarán se crea con el modelo y el resto
     * se copian de él con inserciones masivas, porque crear miles de documentos con el modelo sería inviable.
     * Los movimientos también se insertan directamente, como los tendría un producto con un histórico largo.
     *
     * @param array $products productos del histórico
     * @param array $history número de movimientos por referencia
     *
     * @return int[] los ids de los albaranes, en orden cronológico
     */
    private function createSupplierHistory($proveedor, string $codalmacen, array $products, array $history): array
    {
        $db = new DataBase();

        // creamos el primer albarán con una línea por producto, a primera hora de ayer
        $first = new AlbaranProveedor();
        $this->assertTrue($first->setSubject($proveedor));
        $this->assertTrue($first->setWarehouse($codalmacen));
        $this->assertTrue($first->setDate(Tools::date('-1 day'), '00:00:00'));
        $this->assertTrue($first->save());

        $lineIds = [];
        foreach ($products as $product) {
            $linea = $first->getNewProductLine($product->referencia);
            $linea->cantidad = 1;
            $linea->pvpunitario = 1;
            $this->assertTrue($linea->save());
            $lineIds[$product->referencia] = $linea->id();
        }

        // copiamos el albarán: cada copia tiene un código propio y es un segundo posterior a la anterior
        $docRow = $db->select('SELECT * FROM albaranesprov WHERE idalbaran = ' . $db->var2str($first->id()))[0];
        unset($docRow['idalbaran']);
        $prefix = 'H' . $first->id() . '-';
        $rows = [];
        for ($num = 2; $num <= max($history); $num++) {
            $rows[] = array_merge($docRow, [
                'codigo' => $prefix . $num,
                'hora' => gmdate('H:i:s', $num - 1)
            ]);
        }
        $this->bulkInsert($db, 'albaranesprov', $rows);

        $ids = [1 => $first->id()];
        $sql = 'SELECT idalbaran, codigo FROM albaranesprov WHERE codigo LIKE ' . $db->var2str($prefix . '%');
        foreach ($db->select($sql) as $row) {
            $ids[(int)substr($row['codigo'], strlen($prefix))] = (int)$row['idalbaran'];
        }
        ksort($ids);
        $this->assertCount(max($history), $ids);

        // copiamos las líneas de cada producto y creamos sus movimientos con el saldo acumulado
        $lines = [];
        $movements = [];
        foreach ($products as $product) {
            $lineRow = $db->select('SELECT * FROM lineasalbaranesprov WHERE idlinea = '
                . $db->var2str($lineIds[$product->referencia]))[0];
            unset($lineRow['idlinea']);

            for ($num = 1; $num <= $history[$product->referencia]; $num++) {
                if ($num > 1) {
                    $lines[] = array_merge($lineRow, ['idalbaran' => $ids[$num]]);
                }

                $movements[] = [
                    'cantidad' => 1,
                    'codalmacen' => $codalmacen,
                    'docid' => $ids[$num],
                    'docmodel' => 'AlbaranProveedor',
                    'documento' => 'AlbaranProveedor ' . $ids[$num],
                    'fecha' => $docRow['fecha'],
                    'hora' => gmdate('H:i:s', $num - 1),
                    'idproducto' => $product->idproducto,
                    'referencia' => $product->referencia,
                    'saldo' => $num
                ];
            }
        }
        $this->bulkInsert($db, 'lineasalbaranesprov', $lines);
        $this->bulkInsert($db, 'stocks_movimientos', $movements);

        // las líneas insertadas no actualizan el stock: lo recalculamos con el último movimiento
        $messages = [];
        foreach ($products as $product) {
            StockRebuildManager::rebuild($product->idproducto, $messages, true);
        }

        return array_values($ids);
    }

    /**
     * Elimina los albaranes del histórico. Sus líneas se eliminan en cascada.
     *
     * @param int[] $ids
     */
    private function deleteSupplierHistory(array $ids): void
    {
        $db = new DataBase();
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->assertTrue($db->exec('DELETE FROM albaranesprov WHERE idalbaran IN (' . implode(',', $chunk) . ');'));
        }
    }

    /**
     * Inserta las filas en la tabla por bloques de 500. Todas las filas deben tener las mismas columnas.
     */
    private function bulkInsert(DataBase $db, string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $values[] = '(' . implode(', ', array_map([$db, 'var2str'], $row)) . ')';
            }

            $columns = array_map([$db, 'escapeColumn'], array_keys($chunk[0]));
            $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ')'
                . ' VALUES ' . implode(', ', $values) . ';';
            $this->assertTrue($db->exec($sql));
        }
    }

    /**
     * Devuelve el tiempo máximo aceptable, en segundos, para las pruebas de rendimiento.
     * Se puede ajustar con la variable de entorno STOCKAVANZADO_PERF_BUDGET.
     *
     * @param float $default valor por defecto
     *
     * @return float
     */
    private static function perfBudget(float $default): float
    {
        return (float)(getenv('STOCKAVANZADO_PERF_BUDGET') ?: $default);
    }

    /**
     * Ejecuta la cola de trabajos y devuelve el tiempo empleado, en segundos.
     */
    private function runWorkQueue(): float
    {
        $start = microtime(true);
        while (WorkQueue::run()) {
        }

        return microtime(true) - $start;
    }
}
