<?php


namespace Eliepse\WorkingGrid;


use Eliepse\WorkingGrid\Config\GridConfig;
use Eliepse\WorkingGrid\Config\PageConfig;
use Eliepse\WorkingGrid\Elements\CharacterGroup;
use Eliepse\WorkingGrid\Elements\EmptyGroup;
use Eliepse\WorkingGrid\Elements\Group;
use Eliepse\WorkingGrid\Elements\ModelCharacterGroup;
use Eliepse\WorkingGrid\Elements\Word;
use Eliepse\WorkingGrid\Template\CustomizableFooter;
use Eliepse\WorkingGrid\Template\CustomizableHeader;
use Eliepse\WorkingGrid\Template\Template;
use Eliepse\WorkingGrid\Template\WithDrawingTutorial;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;

class GridPainter
{

    /**
     * @var Mpdf
     */
    private $pdf;

    /**
     * @var Template|WithDrawingTutorial|CustomizableHeader|CustomizableFooter
     */
    private $template;

    /**
     * @var GridConfig
     */
    private $grid_config;

    /**
     * @var PageConfig
     */
    private $page_config;

    /**
     * @var WorkingGrid
     */
    private $workingGrid;

    /**
     * @var float
     */
    protected $ratio;


    public function __construct(WorkingGrid $workingGrid)
    {
        $this->workingGrid = $workingGrid;
        $this->template = $workingGrid->getTemplate();
        $this->grid_config = $workingGrid->getGridConfig();
        $this->page_config = $workingGrid->getPageConfig();
    }


    public function inlinePrint(): void
    {
        $this->paint();
        $this->pdf->Output($this->workingGrid->title . '.pdf', Destination::INLINE);
    }


    public function download(): void
    {
        $this->paint();
        $this->pdf->Output($this->workingGrid->title . '.pdf', Destination::DOWNLOAD);
    }


    /**
     * @return Mpdf
     * @throws MpdfException
     */
    public function paint(): Mpdf
    {
        $this->ratio = $this->grid_config->getUnitSize($this->page_config);
        $this->prepare();

        foreach ($this->workingGrid as $index => $page) {

            $this->drawPage($page);

        }

        return new Mpdf();
    }


    private function drawPage(GridPage $page)
    {
        $this->pdf->AddPage();

        $page_info = new PageInfo($page->getPageNumber(), $this->workingGrid->getPageCount(), []);

        if (is_a($this->template, CustomizableHeader::class)) {
            $this->pdf->SetXY(0, $this->page_config->padding_top);
            $this->template->header($this->pdf, $page_info);
        }

        if (is_a($this->template, CustomizableFooter::class)) {
            $this->pdf->SetXY(0, $this->page_config->page_height - $this->page_config->padding_bottom - $this->page_config->footer_height);
            $this->template->footer($this->pdf, $page_info);
        }

        foreach ($page as $index => $row) {

            $this->drawRow($row);

        }
    }


    private function drawRow(Row $row)
    {
        if ($this->grid_config->draw_tutorial)
            $this->drawTutorial($row);

        /** @var Group $group */
        foreach ($row as $group) {

            $this->drawGroup($group);

        }
    }


    /**
     * @param  $group
     */
    private function drawGroup(Group $group)
    {
        /** @var Cell $column */
        foreach ($group as $column) {

            $this->drawBackground($column);

            $this->drawStrokes($column, is_a($group, ModelCharacterGroup::class));

        }

        $this->drawCellBorders($group);
    }


    private function drawStrokes(Cell $column, bool $model = false): void
    {
        if (get_class($column) === EmptyCell::class)
            return;

        $size = $this->utomm() * .86;
        $offsetInside = $this->utomm() * .07;

        $this->pdf->WriteFixedPosHTML(view($model ? "templates.svg-stroke-model" : "templates.svg-stroke", $this->getSVGData(["strokes" => $column->getStrokes()])),
            $this->bodyToGlobalX($column->getX()) + $offsetInside,
            $this->bodyToGlobalY($column->getY()) + $offsetInside,
            $size,
            $size);
    }


    private function drawBackground(Cell $column): void
    {
        $this->pdf->WriteFixedPosHTML(view("templates.svg-background", $this->getSVGData()),
            $this->bodyToGlobalX($column->getX()),
            $this->bodyToGlobalY($column->getY()),
            $this->utomm(),
            $this->utomm());
    }


    private function drawCellBorders(Group $cell): void
    {
        $this->pdf->Rect($this->bodyToGlobalX($cell->getX()),
            $this->bodyToGlobalY($cell->getY()),
            $this->utomm($cell->getSize()),
            $this->utomm());
    }


    private function drawTutorial(Row $row)
    {
        $offset = $this->grid_config->getTutorialHeight() * .1;
        $txt_size = $this->grid_config->getTutorialHeight() * .8;

        $offsetX = 0;

        /** @var Drawable $drawable */
        foreach ($row->getWord() as $i => $drawable) {

            $strokes = [];

            foreach ($drawable->getStrokes() as $index => $stroke) {

                $strokes[] = $stroke;

                $this->pdf->WriteFixedPosHTML(view("templates.svg-stroke", $this->getSVGData(["strokes" => $strokes])),
                    $this->bodyToGlobalX() + ($index * $txt_size) + $offset + $offsetX,
                    $this->bodyToGlobalY($row->getY()) + $offset - $this->grid_config->getTutorialHeight(),
                    $txt_size,
                    $txt_size);

            }

            $offsetX += (count($drawable->getStrokes()) + 1) * $txt_size;

        }

        $this->pdf->Rect($this->bodyToGlobalX(),
            $this->bodyToGlobalY($row->getY()) - $this->grid_config->getTutorialHeight(),
            $this->utomm($row->getColumnCount()),
            $this->grid_config->getTutorialHeight());
    }


    /**
     * Alias for unitsToMillimeters
     * @param int $unit
     * @return float
     */
    private function utomm(int $unit = 1): float { return $this->unitsToMillimeters($unit); }


    private function bodyToGlobalX(int $unit = 0): float
    {
        return $this->page_config->padding_left + $this->unitsToMillimeters($unit);
    }


    private function bodyToGlobalY(int $unit = 0): float
    {
        return $this->page_config->padding_top + $this->page_config->header_height + (($this->ratio + $this->grid_config->getTutorialHeight()) * $unit);
    }


    /**
     * Convert units to millimeters
     * @param int $unit
     * @return float
     */
    private function unitsToMillimeters(int $unit = 1): float
    {
        return $this->ratio * $unit;
    }


    private function getSVGData(array $data = []): array
    {
        return array_merge([
            "cellBackgroundColor" => $this->grid_config->guide_color,
            "strokeColor"         => $this->grid_config->stroke_color,
            "modelColor"          => $this->grid_config->model_color,
        ], $data);
    }


    /**
     * @return Mpdf
     * @throws MpdfException
     */
    private function prepare(): Mpdf
    {
        $this->pdf = new Mpdf([
            'fontDir'      => [
                resources_path("fonts/"),
            ],
            'fontdata'     => [
                'sourcehansans' => [
                    'R' => 'SourceHanSansSC-Normal.ttf',
                    'B' => 'SourceHanSansSC-Bold.ttf',
                ],
            ],
            'default_font' => 'sourcehansans',
        ]);

        $this->pdf->title = $this->workingGrid->title;

        $this->pdf->cellPaddingT = 0;
        $this->pdf->cellPaddingR = 0;
        $this->pdf->cellPaddingB = 0;
        $this->pdf->cellPaddingL = 0;

        $this->pdf->autoPageBreak = false;
        $this->pdf->autoPadding = false;
        $this->pdf->autoMarginPadding = false;

        return $this->pdf;
    }


}