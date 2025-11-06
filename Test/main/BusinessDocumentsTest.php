<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2024-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PedidoProveedor;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoProveedor;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class BusinessDocumentsTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;
    use DefaultSettingsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testCreateAlbaranCliente(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($customer));
        $this->assertTrue($albaran->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($albaran->save());

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que se ha actualizado el stock del producto
        $this->assertTrue($stock->load($stock->id()));
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);
        $this->assertEquals(0, $stock->pterecibir);
        $this->assertEquals(0, $stock->reservada);

        // comprobamos que hay un movimiento de stock
        $movement = new MovimientoStock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals(-10, $movement->cantidad);
        $this->assertEquals(-10, $movement->saldo);
        $this->assertEquals($albaran->id(), $movement->docid);
        $this->assertEquals($albaran->modelClassName(), $movement->docmodel);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCreateAlbaranProveedor(): void
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

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(10, $stock->cantidad);
        $this->assertEquals(10, $stock->disponible);
        $this->assertEquals(0, $stock->pterecibir);
        $this->assertEquals(0, $stock->reservada);

        // comprobamos que hay un movimiento de stock
        $movement = new MovimientoStock();
        $this->assertTrue($movement->loadWhere($whereRef));
        $this->assertEquals(10, $movement->cantidad);
        $this->assertEquals(10, $movement->saldo);
        $this->assertEquals($albaran->id(), $movement->docid);
        $this->assertEquals($albaran->modelClassName(), $movement->docmodel);

        // eliminamos
        $this->assertTrue($albaran->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCreatePedidoCliente(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un pedido en ese almacén
        $pedido = new PedidoCliente();
        $this->assertTrue($pedido->setSubject($customer));
        $this->assertTrue($pedido->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($pedido->save());

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);
        $this->assertEquals(0, $stock->pterecibir);
        $this->assertEquals(10, $stock->reservada);

        // comprobamos que no hay ningún movimiento de stock
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereRef));

        // eliminamos
        $this->assertTrue($pedido->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCreatePedidoProveedor(): void
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

        // creamos un pedido en ese almacén
        $pedido = new PedidoProveedor();
        $this->assertTrue($pedido->setSubject($proveedor));
        $this->assertTrue($pedido->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($pedido->save());

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($whereRef));
        $this->assertEquals(0, $stock->cantidad);
        $this->assertEquals(0, $stock->disponible);
        $this->assertEquals(10, $stock->pterecibir);
        $this->assertEquals(0, $stock->reservada);

        // comprobamos que no hay ningún movimiento de stock
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereRef));

        // eliminamos
        $this->assertTrue($pedido->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCreatePresupuestoCliente(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un cliente
        $customer = $this->getRandomCustomer();
        $this->assertTrue($customer->save());

        // creamos un presupuesto en ese almacén
        $presupuesto = new PresupuestoCliente();
        $this->assertTrue($presupuesto->setSubject($customer));
        $this->assertTrue($presupuesto->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($presupuesto->save());

        // añadimos una línea al presupuesto
        $linea = $presupuesto->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($stock->loadWhere($whereRef));

        // comprobamos que no hay ningún movimiento de stock
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereRef));

        // eliminamos
        $this->assertTrue($presupuesto->delete());
        $this->assertTrue($customer->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCreatePresupuestoProveedor(): void
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

        // creamos un presupuesto en ese almacén
        $presupuesto = new PresupuestoProveedor();
        $this->assertTrue($presupuesto->setSubject($proveedor));
        $this->assertTrue($presupuesto->setWarehouse($warehouse->codalmacen));
        $this->assertTrue($presupuesto->save());

        // añadimos una línea al presupuesto
        $linea = $presupuesto->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save());

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            Where::eq('codalmacen', $warehouse->codalmacen),
            Where::eq('referencia', $product->referencia)
        ];
        $this->assertFalse($stock->loadWhere($whereRef));

        // comprobamos que no hay ningún movimiento de stock
        $movement = new MovimientoStock();
        $this->assertFalse($movement->loadWhere($whereRef));

        // eliminamos
        $this->assertTrue($presupuesto->delete());
        $this->assertTrue($proveedor->delete());
        $this->assertTrue($proveedor->getDefaultAddress()->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
