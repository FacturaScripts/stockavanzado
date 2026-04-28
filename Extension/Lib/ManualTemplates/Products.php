<?php
/**
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Lib\ManualTemplates;

use Closure;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Dinamic\Lib\CsvFileTools;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * @author Daniel Fernandez Giménez <contacto@danielfg.es>
 */
class Products
{
    /** @var ConteoStock */
    public $conteo;

    public function getConteo(): Closure
    {
        return function (string $codalmacen) {
            if (empty($this->conteo)) {
                $this->conteo = new ConteoStock();
                $this->conteo->codalmacen = $codalmacen;
                $this->conteo->observaciones = 'CSVimport Products';
                $this->conteo->save();
            }

            return $this->conteo;
        };
    }

    public function importAfter(): Closure
    {
        return function (array $data) {
            if (false === Plugins::isEnabled('StockAvanzado') || empty($this->conteo)) {
                return;
            }

            $this->conteo->updateStock();
            $this->conteo = null;
        };
    }

    public function importStockBeforeSave(): Closure
    {
        return function (Stock $stock, array $item, $stockQty) {
            // si tenemos el plugin de StockAvanzado activo, eliminamos la cantidad del stock para que no afecte al stock real
            // ya que la cantidad la gestionamos mediante conteos de stock con el plugin StockAvanzado
            if (Plugins::isEnabled('StockAvanzado') && $stockQty !== null) {
                unset($item['stocks.cantidad']);
            }
        };
    }

    public function importStockAfterSave(): Closure
    {
        return function (Stock $stock, array $item, $stockQty, string $codalmacen, Variante $variant) {
            // si tenemos el plugin StockAvanzado activo creamos un conteo de stock si tenemos cantidad
            if (Plugins::isEnabled('StockAvanzado') && $stockQty !== null) {
                // añadimos el producto al conteo
                $this->getConteo($codalmacen)->addLine(
                    $variant->referencia,
                    $variant->idproducto,
                    CsvFileTools::formatFloat($stockQty)
                );
            }
        };
    }
}
