<?php
/*
 * Xibo - Digitial Signage - http://www.xibo.org.uk
 * Copyright (C) 2009 Daniel Garner
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version. 
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */ 
defined('XIBO') or die("Sorry, you are not allowed to directly access this page.<br /> Please press the back button in your browser.");

class Layout extends Data
{
    private $xml;
    private $DomXml;

    /**
     * Add a layout
     * @param <type> $layout
     * @param <type> $description
     * @param <type> $permissionid
     * @param <type> $tags
     * @param <type> $userid
     * @param <type> $templateId
     * @return <type>
     */
    public function Add($layout, $description, $permissionid, $tags, $userid, $templateId)
    {
        $db          =& $this->db;
        $currentdate = date("Y-m-d H:i:s");

        Debug::LogEntry($db, 'audit', 'Adding new Layout', 'Layout', 'Add');

        // Validation
        if (strlen($layout) > 50 || strlen($layout) < 1)
        {
            $this->SetError(25001, __("Layout Name must be between 1 and 50 characters"));
            return false;
        }

        if (strlen($description) > 254)
        {
            $this->SetError(25002, __("Description can not be longer than 254 characters"));
            return false;
        }

        if (strlen($tags) > 254)
        {
            $this->SetError(25003, __("Tags can not be longer than 254 characters"));
            return false;
        }

        // Ensure there are no layouts with the same name
        $SQL = sprintf("SELECT layout FROM layout WHERE layout = '%s' AND userID = %d ", $layout, $userid);

        if ($db->GetSingleRow($SQL))
        {
            trigger_error($db->error());
            $this->SetError(25004, sprintf(__("You already own a layout called '%s'. Please choose another name."), $layout));
            return false;
        }
        // End Validation

        Debug::LogEntry($db, 'audit', 'Validation Compelte', 'Layout', 'Add');

        // Get the XML for this template.
        $templateXml = $this->GetTemplateXml($templateId);

        Debug::LogEntry($db, 'audit', 'Retrieved template xml', 'Layout', 'Add');

        $SQL = <<<END
        INSERT INTO layout (layout, description, userID, permissionID, createdDT, modifiedDT, tags, xml)
         VALUES ('%s', '%s', %d, %d, '%s', '%s', '%s', '%s')
END;

        $SQL = sprintf($SQL, $db->escape_string($layout),
                            $db->escape_string($description), $userid, $permissionid,
                            $db->escape_string($currentdate),
                            $db->escape_string($currentdate),
                            $db->escape_string($tags),
                            $templateXml);

        if(!$id = $db->insert_query($SQL))
        {
            trigger_error($db->error());
            $this->SetError(25005, __('Could not add Layout'));

            return false;
        }

        Debug::LogEntry($db, 'audit', 'Updating Tags', 'Layout', 'Add');

        // Are there any tags?
        if ($tags != '')
        {
            // Create an array out of the tags
            $tagsArray = explode(' ', $tags);

            // Add the tags XML to the layout
            if (!$this->EditTags($id, $tagsArray))
            {
                $this->Delete($id);
                return false;
            }
        }

        Debug::LogEntry($db, 'audit', 'Complete', 'Layout', 'Add');

        return $id;
    }

    /**
     * Gets the XML for the specified template id
     * @param <type> $templateId
     */
    private function GetTemplateXml($templateId)
    {
        $db =& $this->db;

        if ($templateId == 0)
        {
            // make some default XML
            $xmlDoc = new DOMDocument("1.0");
            $layoutNode = $xmlDoc->createElement("layout");

            $layoutNode->setAttribute("width", 800);
            $layoutNode->setAttribute("height", 450);
            $layoutNode->setAttribute("bgcolor", "#000000");
            $layoutNode->setAttribute("schemaVersion", Config::Version($db, 'XlfVersion'));

            $xmlDoc->appendChild($layoutNode);

            $xml = $xmlDoc->saveXML();
        }
        else
        {
            // Get the template XML
            if (!$row = $db->GetSingleRow(sprintf("SELECT xml FROM template WHERE templateID = %d ", $templateId)))
                trigger_error(__('Error getting this template.'), E_USER_ERROR);

            $xml = $row['xml'];
        }

        return $xml;
    }

    /**
     * Edit Tags for a layout
     * @param <type> $layoutID
     * @param <type> $tags
     * @return <type>
     */
    public function EditTags($layoutID, $tags)
    {
        $db =& $this->db;

        Debug::LogEntry($db, 'audit', 'IN', 'Layout', 'EditTags');

        // Make sure we get an array
        if(!is_array($tags))
        {
            $this->SetError(25006, 'Must pass EditTags an array');
            return false;
        }

        // Set the XML
        if (!$this->SetXml($layoutID))
        {
            Debug::LogEntry($db, 'audit', 'Failed to Set the layout Xml.', 'Layout', 'EditTags');
            return false;
        }

        Debug::LogEntry($db, 'audit', 'Got the XML from the DB. Now creating the tags.', 'Layout', 'EditTags');

        // Create the tags XML
        $tagsXml = '<tags>';

        foreach($tags as $tag)
        {
            $tagsXml .= sprintf('<tag>%s</tag>', $tag);
        }

        $tagsXml .= '</tags>';

        Debug::LogEntry($db, 'audit', 'Tags XML is:' . $tagsXml, 'Layout', 'EditTags');

        // Load the tags XML into a document
        $tagsXmlDoc = new DOMDocument('1.0');
        $tagsXmlDoc->loadXML($tagsXml);


        // Load the XML for this layout
        $xml = new DOMDocument("1.0");
        $xml->loadXML($this->xml);

        // Import the new node into this document
        $newTagsNode = $xml->importNode($tagsXmlDoc->documentElement, true);

        // Xpath for an existing tags node
        $xpath 	= new DOMXPath($xml);
        $tagsNode 	= $xpath->query("//tags");

        // Does the tags node exist?
        if ($tagsNode->length < 1)
        {
            // We need to append our new node to the layout node
            $layoutXpath	= new DOMXPath($xml);
            $layoutNode 	= $xpath->query("//layout");
            $layoutNode 	= $layoutNode->item(0);

            $layoutNode->appendChild($newTagsNode);
        }
        else
        {
            // We need to swap our new node with the existing one
            $tagsNode = $tagsNode->item(0);

            // Replace the node
            $tagsNode->parentNode->replaceChild($newTagsNode, $tagsNode);
        }

        // Format the output a bit nicer for Alex
        $xml->formatOutput = true;

        // Convert back to XML
        $xml = $xml->saveXML();

        Debug::LogEntry($db, 'audit', $xml, 'layout', 'EditTags');

        // Save it
        if (!$this->SetLayoutXml($layoutID, $xml)) return false;

        Debug::LogEntry($db, 'audit', 'OUT', 'Layout', 'EditTags');

        return true;
    }

    /**
     * Sets the Layout XML for this layoutid
     * @return
     * @param $layoutID Object
     */
    private function SetXml($layoutID)
    {
        if(!$this->xml = $this->GetLayoutXml($layoutID))
            return false;

        return true;
    }

    private function SetDomXml($layoutId)
    {
        if (!$this->SetXml($layoutId))
            return false;

        $this->DomXml = new DOMDocument("1.0");

        Debug::LogEntry($this->db, 'audit', 'Loading LayoutXml into the DOM', 'layout', 'SetDomXML');

        if (!$this->DomXml->loadXML($this->xml))
            return false;

        Debug::LogEntry($this->db, 'audit', 'Loaded LayoutXml into the DOM', 'layout', 'SetDomXML');

        return true;
    }

    /**
     * Gets the Xml for the specified layout
     * @return
     * @param $layoutid Object
     */
    private function GetLayoutXml($layoutid)
    {
        $db =& $this->db;

        Debug::LogEntry($db, 'audit', 'IN', 'Layout', 'GetLayoutXml');

        //Get the Xml for this Layout from the DB
        $SQL = sprintf("SELECT xml FROM layout WHERE layoutID = %d ", $layoutid);

        if (!$results = $db->query($SQL))
        {
            trigger_error($db->error());
            $this->SetError(25000, 'Layout does not exist.');
            return false;
        }

        $row = $db->get_row($results) ;

        Debug::LogEntry($db, 'audit', 'OUT', 'Layout', 'GetLayoutXml');

        return $row[0];
    }

    /**
     * Sets the Layout Xml and writes it back to the database
     * @return
     * @param $layoutid Object
     * @param $xml Object
     */
    private function SetLayoutXml($layoutid, $xml)
    {
        $db =& $this->db;

        Debug::LogEntry($db, 'audit', 'IN', 'Layout', 'SetLayoutXml');

        $xml = addslashes($xml);

        // Write it back to the database
        $SQL = sprintf("UPDATE layout SET xml = '%s' WHERE layoutID = %d ", $xml, $layoutid);


        if (!$db->query($SQL))
        {
            trigger_error($db->error());
            $this->SetError(25007, 'Unable to Update Layout.');
            return false;
        }

        Debug::LogEntry($db, 'audit', 'OUT', 'Layout', 'SetLayoutXml');

        return true;
    }

    /**
     * Copys a Layout
     * @param <int> $oldLayoutId
     * @param <string> $newLayoutName
     * @param <int> $userId
     * @return <int> 
     */
    public function Copy($oldLayoutId, $newLayoutName, $userId)
    {
        $db =& $this->db;
        $currentdate = date("Y-m-d H:i:s");

        // The Layout ID is the old layout
        $SQL  = "";
        $SQL .= " INSERT INTO layout (layout, permissionID, xml, userID, description, tags, templateID, retired, duration, background, createdDT, modifiedDT) ";
        $SQL .= " SELECT '%s', permissionID, xml, %d, description, tags, templateID, retired, duration, background, '%s', '%s' ";
        $SQL .= "  FROM layout ";
        $SQL .= " WHERE layoutid = %d";
        $SQL = sprintf($SQL, $db->escape_string($newLayoutName), $userId, $db->escape_string($currentdate), $db->escape_string($currentdate), $oldLayoutId);

        Debug::LogEntry($db, 'audit', $SQL, 'layout', 'Copy');

        if (!$newLayoutId = $db->insert_query($SQL))
        {
            trigger_error($db->error());
            $this->SetError(25000, __('Unable to Copy this Layout'));
            return false;
        }

        // Open the layout XML and parse for media nodes
        if (!$this->SetDomXml($newLayoutId))
        {
            $this->Delete($newLayoutId);
            $this->SetError(25000, __('Unable to copy layout'));
            return false;
        }

        Debug::LogEntry($this->db, 'audit', 'Loaded XML into the DOM.', 'layout', 'Copy');
        
        // Get all media nodes
        $xpath = new DOMXpath($this->DomXml);

        // Create an XPath to get all media nodes
        $mediaNodes = $xpath->query("//media");

        Debug::LogEntry($this->db, 'audit', 'About to loop through media nodes', 'layout', 'Copy');
        
        // On each media node, take the existing LKID and MediaID and create a new LK record in the database
        foreach ($mediaNodes as $mediaNode)
        {
            $mediaId = $mediaNode->getAttribute('id');
            $type = $mediaNode->getAttribute('type');

            Debug::LogEntry($this->db, 'audit', sprintf('Media %s node found with id %d', $type, $mediaId), 'layout', 'Copy');

            // If this is a non region specific type, then move on
            if ($this->IsRegionSpecific($type))
                continue;

            // Get the regionId
            $regionNode = $mediaNode->parentNode;
            $regionId = $regionNode->getAttribute('id');

            // Add the database link for this media record
            if (!$lkId = $this->AddLk($newLayoutId, $regionId, $mediaId))
            {
                $this->Delete($newLayoutId);
                return false;
            }

            // Set this LKID on the media node
            $mediaNode->setAttribute('lkid', $lkId);
        }

        Debug::LogEntry($this->db, 'audit', 'Finished looping through media nodes', 'layout', 'Copy');

        // Set the XML
        $this->SetLayoutXml($newLayoutId, $this->DomXml->saveXML());
        
        // Return the new layout id
        return $newLayoutId;
    }

    /**
     * Deletes a layout
     * @param <type> $layoutId
     * @return <type>
     */
    public function Delete($layoutId)
    {
        $db =& $this->db;

        // Remove all LK records for this layout
        $db->query(sprintf('DELETE FROM lklayoutmedia WHERE layoutid = %d', $layoutId));

        // Remove the Layout
        if (!$db->query(sprintf('DELETE FROM layout WHERE layoutid = %d', $layoutId)))
            return $this->SetError(25008, __('Unable to delete layout'));

        return true;
    }

    /**
     * Adds a DB link between a layout and its media
     * @param <type> $layoutid
     * @param <type> $region
     * @param <type> $mediaid
     * @return <type>
     */
    private function AddLk($layoutid, $region, $mediaid)
    {
        $db =& $this->db;

        $SQL = sprintf("INSERT INTO lklayoutmedia (layoutID, regionID, mediaID) VALUES (%d, '%s', %d)", $layoutid, $db->escape_string($region), $mediaid);

        if (!$id = $db->insert_query($SQL))
        {
            trigger_error($db->error());
            $this->SetError('25999',__("Database error adding this link record."));
            return false;
        }

        return $id;
    }

    /**
     * Is a module type region specific?
     * @param <bool> $type
     */
    private function IsRegionSpecific($type)
    {
        $sql = sprintf("SELECT RegionSpecific FROM module WHERE Module = '%s'", $this->db->escape_string($type));

        Debug::LogEntry($this->db, 'audit', sprintf('Checking to see if %s is RegionSpecific with SQL %s', $type, $sql), 'layout', 'Copy');

        return ($this->db->GetSingleValue($sql, 'RegionSpecific', _INT) == 1) ? true : false;
    }
}
?>
