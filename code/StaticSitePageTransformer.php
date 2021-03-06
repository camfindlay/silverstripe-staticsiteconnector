<?php

class StaticSitePageTransformer implements ExternalContentTransformer
{

    public function transform($item, $parentObject, $duplicateStrategy)
    {
        if (Director::is_cli()) {
            Debug::message("Parent: #$parentObject->ID, $parentObject->Title");
            Debug::message($item->AbsoluteURL);
        }

        // Sleep for 100ms to reduce load on the remote server
        usleep(100*1000);

        // Extract content from the page
        $contentFields = $this->getContentFieldsAndSelectors($item);

        // Default value for Title
        if (empty($contentFields['Title'])) {
            $contentFields['Title'] = array('content' => $item->Name);
        }

        // Default value for URL segment
        if (empty($contentFields['URLSegment'])) {
            $urlSegment = str_replace('/', '', $item->Name);
            $urlSegment = preg_replace('/\.[^.]*$/', '', $urlSegment);
            $urlSegment = str_replace('.', '-', $item->Name);
            $contentFields['URLSegment'] = array('content' => $urlSegment);
        }

        $schema = $item->getSource()->getSchemaForURL($item->AbsoluteURL);

        $pageType = $schema->DataType;

        if (!$pageType) {
            throw new Exception('Pagetype for migration schema is empty!');
        }

        // Create a page with the appropriate fields
        $page = new $pageType(array());
        $existingPage = SiteTree::get_by_link($item->getExternalId());

        if ($existingPage && $duplicateStrategy === 'Overwrite') {
            if (get_class($existingPage) !== $pageType) {
                $existingPage->ClassName = $pageType;
                $existingPage->write();
            }
            if ($existingPage) {
                $page = $existingPage;
            }
        }

        $page->StaticSiteContentSourceID = $item->getSource()->ID;
        $page->StaticSiteURL = $item->AbsoluteURL;

        $page->ParentID = $parentObject ? $parentObject->ID : 0;

        foreach ($contentFields as $k => $v) {
            $page->$k = $v['content'];
        }

        $page->write();

        if (Director::is_cli()) {
            Debug::message("#$page->Title");
            Debug::message("#$page->ID child of #$page->ID");
        }

        return new TransformResult($page, $item->stageChildren());
    }

    /**
     * Get content from the remote host
     * 
     * @param  StaticSiteeContentItem $item The item to extract
     * @return array A map of field name => array('selector' => selector, 'content' => field content)
     */
    public function getContentFieldsAndSelectors($item)
    {
        // Get the import rules from the content source
        $importSchema = $item->getSource()->getSchemaForURL($item->AbsoluteURL);
        if (!$importSchema) {
            return null;
            throw new LogicException("Couldn't find an import schema for $item->AbsoluteURL");
        }
        $importRules = $importSchema->getImportRules();

        // Extract from the remote page based on those rules
        $contentExtractor = new StaticSiteContentExtractor($item->AbsoluteURL);

        return $contentExtractor->extractMapAndSelectors($importRules);
    }
}
