<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factur-X / ZUGFeRD PDF: the invoice PDF as PDF/A-3b with the CII invoice XML embedded.
 *
 * Built on the bundled Dompdf 3, which writes PDF/A-3b itself (sRGB output intent, XMP
 * metadata, embedded fonts) and can attach files. This class adds the Factur-X parts:
 * the XML attachment with AFRelationship "Alternative" and the Factur-X XMP extension
 * schema. No extra library is needed.
 *
 * Profiles:
 * - en16931:   Factur-X 1.07 / ZUGFeRD 2.3 profile EN 16931, attachment factur-x.xml.
 * - xrechnung: ZUGFeRD 2.3 profile XRECHNUNG, attachment xrechnung.xml, CII in XRechnung 3.0.
 *
 * Checked with the Mustang validator (veraPDF PDF/A-3b plus the Factur-X schematron).
 */
class OBLT_FacturX {

	const FX_NS = 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';

	/**
	 * Whether the Dompdf that is loaded can write PDF/A-3 with attachments. A host copy of
	 * Dompdf 1.x or 2.x loaded by another plugin cannot.
	 *
	 * @return bool
	 */
	public static function engine_ready() {
		if ( ! class_exists( 'OBLT_Pdf' ) || ! OBLT_Pdf::load_engine() ) {
			return false;
		}
		return class_exists( '\\Dompdf\\Cpdf' )
			&& method_exists( '\\Dompdf\\Cpdf', 'addEmbeddedFile' )
			&& method_exists( '\\Dompdf\\Cpdf', 'setAdditionalXmpRdf' )
			&& method_exists( '\\Dompdf\\Options', 'setIsPdfAEnabled' );
	}

	/**
	 * Attachment name and XMP conformance level for a profile. Pure.
	 *
	 * @param string $profile 'en16931' | 'xrechnung'.
	 * @return array{filename:string,level:string}
	 */
	public static function profile_meta( $profile ) {
		if ( 'xrechnung' === $profile ) {
			return array(
				'filename' => 'xrechnung.xml',
				'level'    => 'XRECHNUNG',
			);
		}
		return array(
			'filename' => 'factur-x.xml',
			'level'    => 'EN 16931',
		);
	}

	/**
	 * The Factur-X XMP: the fx properties plus the PDF/A extension schema that declares
	 * them (PDF/A requires custom XMP properties to be declared). Pure.
	 *
	 * @param string $filename Attachment name.
	 * @param string $level    Conformance level.
	 * @return string RDF descriptions to add inside rdf:RDF.
	 */
	public static function xmp_rdf( $filename, $level ) {
		$esc   = static function ( $v ) {
			return htmlspecialchars( (string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};
		$props = array(
			'DocumentFileName' => 'The name of the embedded XML document',
			'DocumentType'     => 'The type of the hybrid document in capital letters, e.g. INVOICE or ORDER',
			'Version'          => 'The actual version of the standard applying to the embedded XML document',
			'ConformanceLevel' => 'The conformance level of the embedded XML document',
		);
		$list  = '';
		foreach ( $props as $name => $desc ) {
			$list .= '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>' . $name . '</pdfaProperty:name>'
				. '<pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category>'
				. '<pdfaProperty:description>' . $desc . '</pdfaProperty:description></rdf:li>';
		}
		return "\n" . '<rdf:Description xmlns:fx="' . self::FX_NS . '" rdf:about="">'
			. '<fx:DocumentType>INVOICE</fx:DocumentType>'
			. '<fx:DocumentFileName>' . $esc( $filename ) . '</fx:DocumentFileName>'
			. '<fx:Version>1.0</fx:Version>'
			. '<fx:ConformanceLevel>' . $esc( $level ) . '</fx:ConformanceLevel>'
			. '</rdf:Description>'
			. "\n" . '<rdf:Description xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#" rdf:about="">'
			. '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
			. '<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
			. '<pdfaSchema:namespaceURI>' . self::FX_NS . '</pdfaSchema:namespaceURI>'
			. '<pdfaSchema:prefix>fx</pdfaSchema:prefix>'
			. '<pdfaSchema:property><rdf:Seq>' . $list . '</rdf:Seq></pdfaSchema:property>'
			. '</rdf:li></rdf:Bag></pdfaExtension:schemas></rdf:Description>';
	}

	/**
	 * PDF/A only allows embedded fonts. Dompdf's built-in Helvetica, Times and Courier are
	 * not embedded, so every element is set to the bundled DejaVu Sans (regular and bold).
	 * Italic is dropped because the oblique font file is not bundled. Pure.
	 *
	 * @param string $html Document HTML.
	 * @return string
	 */
	public static function pdfa_html( $html ) {
		$css  = '<style id="oblt-pdfa-fonts">*{font-family:"DejaVu Sans"!important;font-style:normal!important}</style>';
		$html = (string) $html;
		$pos  = strripos( $html, '</head>' );
		return false === $pos ? $css . $html : substr( $html, 0, $pos ) . $css . substr( $html, $pos );
	}

	/**
	 * Remove images, for the retry when Dompdf refuses an image in PDF/A mode (CMYK JPEG). Pure.
	 *
	 * @param string $html Document HTML.
	 * @return string
	 */
	public static function strip_images( $html ) {
		return (string) preg_replace( '/<img\b[^>]*>/i', '', (string) $html );
	}

	/**
	 * Attach the XML and the Factur-X XMP to a rendered PDF/A Dompdf, then output it.
	 *
	 * @param \Dompdf\Dompdf $dompdf  Rendered with isPdfAEnabled.
	 * @param string         $xml     CII XML.
	 * @param string         $profile 'en16931' | 'xrechnung'.
	 * @return string PDF bytes.
	 * @throws RuntimeException When the XML cannot be staged.
	 */
	public static function finish( $dompdf, $xml, $profile ) {
		$meta = self::profile_meta( $profile );
		$cpdf = $dompdf->getCanvas()->get_cpdf();
		// Cpdf reads the attachment from disk when it writes the PDF.
		$tmp = wp_tempnam( 'oblt-facturx' );
		if ( ! $tmp || false === file_put_contents( $tmp, (string) $xml ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new RuntimeException( 'Could not write the temporary invoice XML.' );
		}
		try {
			$cpdf->addEmbeddedFile( $tmp, $meta['filename'], 'Factur-X invoice', 'text/xml', array( $cpdf->catalogId => 'Alternative' ) );
			$cpdf->setAdditionalXmpRdf( self::xmp_rdf( $meta['filename'], $meta['level'] ) );
			$bytes = (string) $dompdf->output();
		} finally {
			wp_delete_file( $tmp );
		}
		return $bytes;
	}
}
