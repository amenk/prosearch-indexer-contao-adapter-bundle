<?php

namespace Alnv\ProSearchIndexerContaoAdapterBundle\Search;

use Alnv\ProSearchIndexerContaoAdapterBundle\Adapter\Elasticsearch;
use Alnv\ProSearchIndexerContaoAdapterBundle\Adapter\Options;
use Alnv\ProSearchIndexerContaoAdapterBundle\Helpers\States;
use Alnv\ProSearchIndexerContaoAdapterBundle\Helpers\Text;
use Alnv\ProSearchIndexerContaoAdapterBundle\Models\IndicesModel;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Frontend;
use Contao\File;
use Contao\StringUtil;
use Contao\System;
use Psr\Log\LogLevel;
use Smalot\PdfParser\Parser;
use Contao\CoreBundle\Search\Document;
use Symfony\Component\DomCrawler\Crawler;

/**
 *
 */
class PDFIndices extends Searcher
{

    /**
     * @param Document $document
     * @param array $meta
     * @throws \Exception
     */
    public function __construct(Document $document, array $meta = [])
    {

        set_time_limit(500);

        try {
            $strLanguage = $document->getContentCrawler()->filterXPath('//html[@lang]')->first()->attr('lang');
        } catch (\Exception $e) {
            $strLanguage = 'en';
        }

        $_strUrl = $document->getUri()->__toString();
        $arrDomain = parse_url($_strUrl);
        $strDomain = ($arrDomain['scheme'] ?? '')
            . '://'
            . ($arrDomain['host'] ?? '')
            . (isset($arrDomain['port']) && $arrDomain['port'] ? ':' . $arrDomain['port'] : '');

        $strHtml = $document->getBody();
        $this->objCrawler = new Crawler($strHtml);
        $objLinks = $this->objCrawler->filter("body a");

        foreach ($objLinks as $objLink) {

            $strHref = $objLink->getAttribute('href');

            if (!$strHref || strpos($strHref, '.pdf') === false) {
                continue;
            }

            $arrUrl = parse_url($strHref);
            $strFile = $arrUrl['path'];
            $objFile = \FilesModel::findByPath($strFile);

            if (!$objFile) {
                continue;
            }

            $_File = new File($objFile->path);
            if (($_File->filesize / 1000001) > 5) {
                continue;
            }

            $arrMeta = Frontend::getMetaData(\StringUtil::deserialize($objFile->meta), $strLanguage);

            $strNodeContent = Text::tokenize($objLink->textContent);
            $strTitleAttr = $objLink->getAttribute('title');
            $strMetaDescription = $arrMeta['caption'] ?? '';
            $strMetaTitle = $arrMeta['title'] ?? '';
            $strMetaAlt = $arrMeta['alt'] ?? '';
            $strFilename = StringUtil::specialchars($_File->basename);

            try {

                $objParser = new Parser();
                $objPdf = $objParser->parseFile(TL_ROOT . '/' . $objFile->path);

                $arrText = [$strNodeContent, $strTitleAttr, $strMetaDescription, $strMetaTitle, $strMetaAlt, $strFilename];
                $arrText = array_filter($arrText);

                $arrDocument = [
                    'text' => $arrText,
                    'document' => [Text::tokenize($objPdf->getText())],
                    'strong' => [],
                    'h1' => [],
                    'h2' => [],
                    'h3' => [],
                    'h4' => [],
                    'h5' => [],
                    'h6' => []
                ];

                if ($strNodeContent) {
                    $arrDocument['strong'][] = $strNodeContent;
                }

                $strUrl = $strDomain . '/' . $objFile->path;
                $objIndicesModel = IndicesModel::findByUrl($strUrl);

                if (!$objIndicesModel) {
                    $objIndicesModel = new IndicesModel();
                }

                $objPage = \PageModel::findByPk($meta['pageId']);
                $objPage->loadDetails();

                $objIndicesModel->tstamp = time();
                $objIndicesModel->url = $strUrl;
                $objIndicesModel->origin_url = $_strUrl;
                $objIndicesModel->state = States::ACTIVE;
                $objIndicesModel->language = $strLanguage;
                $objIndicesModel->types = ['pdf'];
                $objIndicesModel->pageId = $objPage->id;
                $objIndicesModel->images = ['assets/contao/images/pdf.svg'];
                $objIndicesModel->document = serialize($arrDocument);
                $objIndicesModel->domain = $document->getUri()->getHost();
                $objIndicesModel->title = (($strMetaTitle ?: $strNodeContent) ?: $strFilename);
                $objIndicesModel->description = ($strMetaDescription ?: $strMetaAlt);
                $objIndicesModel->doc_type = 'file';
                $objIndicesModel->save();

                $objOptions = new Options();
                $objOptions->setLanguage($strLanguage);
                $objOptions->setRootPageId($objPage->rootId);

                (new Elasticsearch($objOptions->getOptions()))->indexDocuments($objIndicesModel->id);

            } catch (\Exception $exception) {

                System::getContainer()
                    ->get('monolog.logger.contao')
                    ->log(LogLevel::ERROR, 'PDF Parser ('.$objFile->path.'): ' . $exception->getMessage(), ['contao' => new ContaoContext(__CLASS__ . '::' . __FUNCTION__, TL_ERROR)]);
            }
        }
    }
}