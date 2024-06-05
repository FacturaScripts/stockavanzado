<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PedidoProveedor;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoProveedor;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class BusinessDocumentsTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testCreateAlbaranCliente(): void
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // añadimos stock al producto
        $stock = new Stock();
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $almacen->codalmacen;
        $stock->cantidad = 10;
        $stock->disponible = 10;
        $this->assertTrue($stock->save(), 'stock-can-not-be-saved');

        // creamos un cliente
        $cliente = $this->getRandomCustomer();
        $this->assertTrue($cliente->save(), 'customer-can-not-be-saved');

        // creamos un albarán en ese almacén
        $albaran = new AlbaranCliente();
        $this->assertTrue($albaran->setSubject($cliente));
        $this->assertTrue($albaran->setWarehouse($almacen->codalmacen));
        $this->assertTrue($albaran->save(), 'albaran-can-not-be-saved');

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save(), 'line-can-not-be-saved');

        // comprobamos que se ha actualizado el stock del producto
        $this->assertTrue($stock->loadFromCode($stock->primaryColumnValue()));
        $this->assertEquals(0, $stock->cantidad, 'stock-should-be-ten');
        $this->assertEquals(0, $stock->disponible, 'stock-available-should-be-ten');
        $this->assertEquals(0, $stock->pterecibir, 'stock-to-receive-should-be-zero');
        $this->assertEquals(0, $stock->reservada, 'stock-reserved-should-be-zero');

        // comprobamos que hay un movimiento de stock
        $movimiento = new MovimientoStock();
        $whereRef = [
            new DataBaseWhere('codalmacen', $almacen->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertTrue($movimiento->loadFromCode('', $whereRef), 'stock-movement-can-be-loaded');
        $this->assertEquals(-10, $movimiento->cantidad, 'stock-movement-quantity-should-be-ten');
        $this->assertEquals($albaran->primaryColumnValue(), $movimiento->docid, 'stock-movement-docid-should-be-albaran-id');
        $this->assertEquals($albaran->modelClassName(), $movimiento->docmodel, 'stock-movement-docname-should-be-albaran');

        // eliminamos
        $this->assertTrue($albaran->delete(), 'albaran-can-not-be-deleted');
        $this->assertTrue($cliente->delete(), 'customer-can-not-be-deleted');
        $this->assertTrue($cliente->getDefaultAddress()->delete(), 'address-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }

    public function testCreateAlbaranProveedor(): void
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save(), 'supplier-can-not-be-saved');

        // creamos el albarán de proveedor
        $albaran = new AlbaranProveedor();
        $this->assertTrue($albaran->setSubject($proveedor));
        $this->assertTrue($albaran->setWarehouse($almacen->codalmacen));
        $this->assertTrue($albaran->save(), 'albaran-can-not-be-saved');

        // añadimos una línea al albarán
        $linea = $albaran->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save(), 'line-can-not-be-saved');

        // comprobamos que hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            new DataBaseWhere('codalmacen', $almacen->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadFromCode('', $whereRef), 'stock-can-be-loaded');
        $this->assertEquals(10, $stock->cantidad, 'stock-should-be-ten');
        $this->assertEquals(10, $stock->disponible, 'stock-available-should-be-ten');
        $this->assertEquals(0, $stock->pterecibir, 'stock-to-receive-should-be-zero');
        $this->assertEquals(0, $stock->reservada, 'stock-reserved-should-be-zero');

        // comprobamos que hay un movimiento de stock
        $movimiento = new MovimientoStock();
        $this->assertTrue($movimiento->loadFromCode('', $whereRef), 'stock-movement-can-not-be-loaded');
        $this->assertEquals(10, $movimiento->cantidad, 'stock-movement-quantity-should-be-ten');
        $this->assertEquals($albaran->primaryColumnValue(), $movimiento->docid, 'stock-movement-docid-should-be-albaran-id');
        $this->assertEquals($albaran->modelClassName(), $movimiento->docmodel, 'stock-movement-docname-should-be-albaran');

        // eliminamos
        $this->assertTrue($albaran->delete(), 'albaran-can-not-be-deleted');
        $this->assertTrue($proveedor->delete(), 'supplier-can-not-be-deleted');
        $this->assertTrue($proveedor->getDefaultAddress()->delete(), 'address-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }

    public function testCreatePedidoCliente(): void
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // creamos un cliente
        $cliente = $this->getRandomCustomer();
        $this->assertTrue($cliente->save(), 'customer-can-not-be-saved');

        // creamos un pedido en ese almacén
        $pedido = new PedidoCliente();
        $this->assertTrue($pedido->setSubject($cliente));
        $this->assertTrue($pedido->setWarehouse($almacen->codalmacen));
        $this->assertTrue($pedido->save(), 'pedido-can-not-be-saved');

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save(), 'line-can-not-be-saved');

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            new DataBaseWhere('codalmacen', $almacen->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadFromCode('', $whereRef), 'stock-can-be-loaded');
        $this->assertEquals(0, $stock->cantidad, 'stock-should-be-zero');
        $this->assertEquals(0, $stock->disponible, 'stock-available-should-be-zero');
        $this->assertEquals(0, $stock->pterecibir, 'stock-to-receive-should-be-ten');
        $this->assertEquals(10, $stock->reservada, 'stock-reserved-should-be-zero');

        // comprobamos que no hay ningún movimiento de stock
        $movimiento = new MovimientoStock();
        $this->assertFalse($movimiento->loadFromCode('', $whereRef), 'stock-movement-can-not-be-loaded');

        // eliminamos
        $this->assertTrue($pedido->delete(), 'pedido-can-not-be-deleted');
        $this->assertTrue($cliente->delete(), 'customer-can-not-be-deleted');
        $this->assertTrue($cliente->getDefaultAddress()->delete(), 'address-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }

    public function testCreatePedidoProveedor(): void
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save(), 'supplier-can-not-be-saved');

        // creamos un pedido en ese almacén
        $pedido = new PedidoProveedor();
        $this->assertTrue($pedido->setSubject($proveedor));
        $this->assertTrue($pedido->setWarehouse($almacen->codalmacen));
        $this->assertTrue($pedido->save(), 'pedido-can-not-be-saved');

        // añadimos una línea al pedido
        $linea = $pedido->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save(), 'line-can-not-be-saved');

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            new DataBaseWhere('codalmacen', $almacen->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadFromCode('', $whereRef), 'stock-can-be-loaded');
        $this->assertEquals(0, $stock->cantidad, 'stock-should-be-zero');
        $this->assertEquals(0, $stock->disponible, 'stock-available-should-be-zero');
        $this->assertEquals(10, $stock->pterecibir, 'stock-to-receive-should-be-zero');
        $this->assertEquals(0, $stock->reservada, 'stock-reserved-should-be-zero');

        // comprobamos que no hay ningún movimiento de stock
        $movimiento = new MovimientoStock();
        $this->assertFalse($movimiento->loadFromCode('', $whereRef), 'stock-movement-can-not-be-loaded');

        // eliminamos
        $this->assertTrue($pedido->delete(), 'pedido-can-not-be-deleted');
        $this->assertTrue($proveedor->delete(), 'supplier-can-not-be-deleted');
        $this->assertTrue($proveedor->getDefaultAddress()->delete(), 'address-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }

    public function testCreatePresupuestoCliente(): void
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // creamos un cliente
        $cliente = $this->getRandomCustomer();
        $this->assertTrue($cliente->save(), 'customer-can-not-be-saved');

        // creamos un presupuesto en ese almacén
        $presupuesto = new PresupuestoCliente();
        $this->assertTrue($presupuesto->setSubject($cliente));
        $this->assertTrue($presupuesto->setWarehouse($almacen->codalmacen));
        $this->assertTrue($presupuesto->save(), 'presupuesto-can-not-be-saved');

        // añadimos una línea al presupuesto
        $linea = $presupuesto->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save(), 'line-can-not-be-saved');

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            new DataBaseWhere('codalmacen', $almacen->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertFalse($stock->loadFromCode('', $whereRef), 'stock-can-be-loaded');

        // comprobamos que no hay ningún movimiento de stock
        $movimiento = new MovimientoStock();
        $this->assertFalse($movimiento->loadFromCode('', $whereRef), 'stock-movement-can-not-be-loaded');

        // eliminamos
        $this->assertTrue($presupuesto->delete(), 'presupuesto-can-not-be-deleted');
        $this->assertTrue($cliente->delete(), 'customer-can-not-be-deleted');
        $this->assertTrue($cliente->getDefaultAddress()->delete(), 'address-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }

    public function testCreatePresupuestoProveedor(): void
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // creamos un proveedor
        $proveedor = $this->getRandomSupplier();
        $this->assertTrue($proveedor->save(), 'supplier-can-not-be-saved');

        // creamos un presupuesto en ese almacén
        $presupuesto = new PresupuestoProveedor();
        $this->assertTrue($presupuesto->setSubject($proveedor));
        $this->assertTrue($presupuesto->setWarehouse($almacen->codalmacen));
        $this->assertTrue($presupuesto->save(), 'presupuesto-can-not-be-saved');

        // añadimos una línea al presupuesto
        $linea = $presupuesto->getNewProductLine($product->referencia);
        $linea->cantidad = 10;
        $linea->pvpunitario = 10;
        $this->assertTrue($linea->save(), 'line-can-not-be-saved');

        // comprobamos que no hay stock del producto en el almacén
        $stock = new Stock();
        $whereRef = [
            new DataBaseWhere('codalmacen', $almacen->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertFalse($stock->loadFromCode('', $whereRef), 'stock-can-be-loaded');

        // comprobamos que no hay ningún movimiento de stock
        $movimiento = new MovimientoStock();
        $this->assertFalse($movimiento->loadFromCode('', $whereRef), 'stock-movement-can-not-be-loaded');

        // eliminamos
        $this->assertTrue($presupuesto->delete(), 'presupuesto-can-not-be-deleted');
        $this->assertTrue($proveedor->delete(), 'supplier-can-not-be-deleted');
        $this->assertTrue($proveedor->getDefaultAddress()->delete(), 'address-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
