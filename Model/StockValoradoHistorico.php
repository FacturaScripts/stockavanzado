<?php
namespace FacturaScripts\Plugins\StockAvanzado\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Almacen;

/**
 * Modelo para histórico de stock valorado
 */
class StockValoradoHistorico extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $codalmacen;

    /** @var string */
    public $fecha;

    /** @var float */
    public $total_coste;

    /** @var float */
    public $total_precio;

    public function clear(): void
    {
        parent::clear();
        $this->total_coste = 0.0;
        $this->total_precio = 0.0;
    }

    public function install(): string
    {
        // dependencias
        new Almacen();

        return parent::install();
    }

    public static function tableName(): string
    {
        return 'stock_valorado_historico';
    }

    public function test(): bool
    {
        $this->fecha = Tools::noHtml($this->fecha);
        return parent::test();
    }
}