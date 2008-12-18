<?php
/**
 * vCard serializer, which uses Portable Contacts as the input object format.
 * @see http://en.wikipedia.org/wiki/VCard
 * @see http://www.isi.edu/in-notes/rfc2426.txt
 * @see http://portablecontacts.net
 * @author Joseph Smarr (joseph@plaxo.com)
 * 
 * Sample usage:
 *
 * // $entries is an array of contact objects in Portable Contacts format:
 * $vCardData = VcardSerializer::serializeContacts($entries);
 *
 * // $entry is single contact object in Portable Contacts format:
 * $vCardData = VcardSerializer::serializeContact($entry);
 *
 * TODO:
 * - ensure N,FN are always present (but we can't control input...)
 * - ensure TYPE values conform to vCard (or else prepend with "x-"?)
 * - allow optional SOURCE url to be passed in along with entry/entries?
 */

class VcardSerializer {

  /**
   * Serializes a list of contact objects into a concatenated list of vCards.
   * @param $entries array of contact objects to serialize (in Portable Contacts format).
   * @return vCard text for these contacts as a single string.
   */
  public static function serializeContacts($entries) {
    $vcards = array();
    foreach ($entries as $entry) {
      $vcards[] = self::serializeContact($entry);
    }
    return implode("\n", $vcards);
  }

  /**
   * Serializes a single contact object into a single vCard.
   * @param $entry contact object to serialize (in Portable Contacts format).
   * @return vCard text for this contact as a string.
   */
  public static function serializeContact($entry) {
    $lines = array();
    $lines[] = "BEGIN:VCARD";
    $lines[] = "VERSION:3.0";
    $lines[] = "PRODID:VcardSerializer.php by Plaxo";
    foreach ($entry as $fieldName => $fieldValue) {
      $line =  self::serializeField($fieldName, $fieldValue);
      if ($line) $lines[] = $line;
    }
    $lines[] = "END:VCARD";
    return implode("\n", $lines);
  }

  /**
   * Runs a basic unit test to verify that this class is working as expected.
   * To run this unit test from the command line:
   * php -r 'require_once("VcardSerializer.php"); VcardSerializer::runUnitTest();'
   */
  public static function runUnitTest() {
    /*
      Sample Portable Contacts entry:
      {
        "displayName": "Joseph Smarr",
        "name": {
          "givenName": "Joseph",
          "familyName": "Smarr",
        },
        "note": "in vCard, escape commas",
        "emails": [
          {
            "value": "joseph@plaxo.com",
            "type": "work"
          }
        ],
        "tags": [ 
          "math enthusiast", 
          "badass mc" 
        ]
      }
    */
    $entry = new stdClass();
    $entry->displayName = "Joseph Smarr";
    $entry->name = new stdClass();
    $entry->name->givenName = "Joseph";
    $entry->name->familyName = "Smarr";
    $entry->note = "in vCard, escape commas";
    $email = new stdClass();
    $email->value = "joseph@plaxo.com";
    $email->type = "work";
    $entry->emails = array($email);
    $entry->tags = array("math enthusiast", "badass mc");
    
    $vCardOutput = self::serializeContact($entry);

    $correctOutput = 
      "BEGIN:VCARD\n".
      "VERSION:3.0\n".
      "PRODID:VcardSerializer.php by Plaxo\n".
      "FN:Joseph Smarr\n".
      "N:Smarr;Joseph;;;\n".
      "NOTE:in vCard\, escape commas\n".
      "EMAIL;TYPE=WORK:joseph@plaxo.com\n".
      "CATEGORIES:math enthusiast,badass mc\n".
      "END:VCARD";

    if ($vCardOutput == $correctOutput) {
      echo "Unit test succeeded.\n";
      return true;
    } else {
      echo "Unit test FAILED.\n";
      echo "----------------\n";
      echo "Expected output:\n";
      echo "----------------\n";
      echo "$correctOutput\n";
      echo "--------------\n";
      echo "Actual output:\n";
      echo "--------------\n";
      echo "$vCardOutput\n";
      return false;
    }
  }

  // -------------------------------------------- //
  // --- Internal helper functions below here --- //
  // -------------------------------------------- //

  /** Returns the vCard serialization of the given Portable Contacts field/value, or null if it cannot be serialized. */
  private static function serializeField($fieldName, $fieldValue) {
    $line = null;

    if (is_array($fieldValue) && count($fieldValue) > 0) {
      // plural field
      if (self::isSimplePluralField($fieldName)) {
        // array of simple (string) values -> join values with comma
        return self::serializeSingleField($fieldName, $fieldValue);
      } else if ($fieldName == 'organizations') {
        // extract primary organization and title only (since Portable Contacts supports multiple organizations)
        $fieldValue = self::getPrimaryInstance($fieldValue);
        $line = self::serializeSingleField($fieldName, $fieldValue);
        if (isset($fieldValue->title)) {
          $line = self::serializeSingleField("title", $fieldValue->title);
        }
      } else {
        // normal plural field -> serialize each instance
        foreach ($fieldValue as $fieldValueInstance) {
          $line = self::serializeSingleField($fieldName, $fieldValueInstance);
          if ($fieldName == 'addresses' && isset($fieldValueInstance->formatted)) {
            // also pull out formatted address to serialize as LABEL
            $line = self::serializeSingleField("label", $fieldValueInstance);
          }
        }  
      }
    } else {
      // singular field
      $line = self::serializeSingleField($fieldName, $fieldValue);
    }

    return $line;
  }

  /** Returns the vCard serialization of the given instance of a Portable Contacts field/value. */
  private static function serializeSingleField($fieldName, $fieldValue) {
    $vCardFieldName =  self::getVcardFieldName($fieldName, $fieldValue);
    if (!$vCardFieldName) return null; // unrecognized field -> ignore

    $line = $vCardFieldName;
    if (is_object($fieldValue)) {
      if (isset($fieldValue->type)) {
        $line .= ";TYPE=" . self::serializeTypeValue($fieldValue->type);
      }
      if (isset($fieldValue->primary) && $fieldValue->primary) {
        $line .= ";TYPE=PREF";
      }
    }
    if ($fieldName == 'photos') {
      $line .= ";VALUE=URI";
    }
    $line .= ":";

    $fieldValue = self::serializeFieldValue($fieldName, $fieldValue);
    if (!$fieldValue) return null; // problem serializing value -> abort this line

    $line .= $fieldValue;
    return self::foldLine($line); // fold line only once its fully serialized
  }

  /** Returns the vCard type-value corresponding to the given Portable Contacts type-value. */
  private static function serializeTypeValue($typeValue) {
    if ($typeValue == 'mobile') $typeValue = 'cell';
    return strtoupper($typeValue);
  }

  /** Returns the vCard serialization of the given Portable Contacts field-value. */
  private static function serializeFieldValue($fieldName, $fieldValue) {
    if (is_object($fieldValue)) {
      if (isset($fieldValue->value)) {
        return self::encodeValue($fieldValue->value);
      } else if ($fieldName == "label") {
        return self::encodeValue($fieldValue->formatted);
      } else if (isset(self::$_multiPartFields[$fieldName])) {
        return self::serializeMultiPartFieldValue($fieldName, $fieldValue);
      } else {
        // un-recognized complex field with recognized vCard field-name 
        // but no correponding multi-part vCard representation (shouldn't happen)
        return null;
      }
    } else if (is_array($fieldValue)) {
      // simple plural field -> encode ach value and concatenate with commas 
      return implode(",", array_map(array('self', 'encodeValue'), $fieldValue));
    } else return self::encodeValue($fieldValue);
  }

  /** Returns the vCard serialization of the given (semicolon-delimited) multi-value field. */
  private static function serializeMultiPartFieldValue($fieldName, $fieldValue) {
    $subFields = self::$_multiPartFields[$fieldName];
    $subValues = array();
    foreach ($subFields as $subField) {
      if ($subField && isset($fieldValue->$subField)) {
        $subValues[] = self::encodeValue($fieldValue->$subField);
      } else {
        $subValues[] = "";
      }
    }
    return implode(";", $subValues);
  }

  /** Returns the primary instance of the given plural field value-array. */
  private static function getPrimaryInstance($fieldValues) {
    if (count($fieldValues) == 0) return null;

    foreach ($fieldValues as $fieldValue) {
      if (isset($fieldValue->primary) && $fieldValue->primary) {
        return $fieldValue;
      }
    }

    return $fieldValues[0]; // no explicitly primary instance -> return first one
  }

  /** Escapes all commas, semicolons, and newlines in the given vCard value string, as per the spec. */
  private static function encodeValue($value) {
    $value = str_replace(",",  "\\,", $value);    
    $value = str_replace(";",  "\\;", $value);    
    $value = preg_replace("/\r?\n/", "\\n", $value);    
    return $value;
  }

  /** Encodes the given vCard line using the vCard 75-char line-folding spec. */
  private static function foldLine($line) {
    // insert a newline+space every 75 chars, as per spec
    return preg_replace("/(.{75})(?=.)/", "$1\n ", $line);
  }

  /** Returns the vCard field name for the given Portable Contacts field/value, or null if there is no corresponding field. */
  private static function getVcardFieldName($fieldName, $fieldValue) {
    $vCardFieldName = null;
    if (isset(self::$_fieldNames[$fieldName])) {
      $vCardFieldName = self::$_fieldNames[$fieldName];
    } else if ($fieldName == 'ims' && isset($fieldValue->type) && isset(self::$_imFieldNamesByType[$fieldValue->type])) {
      $vCardFieldName = self::$_imFieldNamesByType[$fieldValue->type];
    } else {
      // can do this to see all unrecognized fields in a quasi-legal output
      //$vCardFieldName = "x-$fieldName";
    }
    return $vCardFieldName;
  }

  /** Returns true iff the given field is a plural field whose instance values are simple strings. */
  private static function isSimplePluralField($fieldName) {
    return in_array($fieldName, self::$_simplePluralFields);
  }
  
  /** Mapping from Portable Contacts field names to vCard field names. */
  private static $_fieldNames = array(
    'id'            => 'UID',
    'name'          => 'N',
    'displayName'   => 'FN',
    'birthday'      => 'BDAY',
    'anniversary'   => 'X-ANNIVERSARY',
    'note'          => 'NOTE',
    'utcOffset'     => 'TZ',
    'nickname'      => 'NICKNAME',
    'updated'       => 'REV',
    'title'         => 'TITLE',
    'label'         => 'LABEL',
    'addresses'     => 'ADR',
    'emails'        => 'EMAIL',
    'urls'          => 'URL',
    'phoneNumbers'  => 'TEL',
    'photos'        => 'PHOTO',
    'tags'          => 'CATEGORIES', 
    'organizations' => 'ORG',
  );

  /** Mapping from Portable Contacts "ims" type-values to vCard field names. */
  private static $_imFieldNamesByType = array(
    'aim'           => 'X-AIM',
    'icq'           => 'X-ICQ',
    'xmpp'          => 'X-JABBER',
    'msn'           => 'X-MSN',
    'yahoo'         => 'X-YAHOO',
    'skype'         => 'X-SKYPE-USERNAME',
  );

  /** Mapping from Portable Contacts complex-fields' sub-fields to their ordering in vCard serialization. */
  private static $_multiPartFields = array(
    'name'          => array('familyName', 'givenName', 'middleName', 'honorificPrefix', 'honorificSuffix'),
    'addresses'     => array('', '', 'streetAddress', 'locality', 'region', 'postalCode', 'country'),
    'organizations' => array('name', 'department'),
  );

  /** List of Portable Contacts field names for plural fields whose instance values are simple strings. */
  private static $_simplePluralFields = array(
    'tags',
    'relationships',
  );
}
