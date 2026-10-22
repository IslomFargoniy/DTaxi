package uz.dtaxi.driver

import org.junit.Assert.assertEquals
import org.junit.Test
import uz.dtaxi.driver.data.Formats

/** Server (tests/Feature/Admin/InputFormatTest.php) va panel bilan bir xil holatlar */
class FormatsTest {
    @Test
    fun phonesInAnyFormNormalizeToNationalPart() {
        listOf("+998901234567", "998901234567", "901234567", "+998 (90) 123-45-67", "8 90 123 45 67", "00998901234567")
            .forEach { assertEquals(it, "901234567", Formats.phoneNational(it)) }
    }

    @Test
    fun typingKeepsAtMostNineDigitsAndPasteStripsPrefix() {
        assertEquals("99812345", Formats.phoneInput("99812345"))
        assertEquals("901234567", Formats.phoneInput("9012345678"))
        assertEquals("901234567", Formats.phoneInput("+998 90 123 45 67"))
    }

    @Test
    fun displayFormats() {
        assertEquals("90 123", Formats.formatPhoneNational("90123"))
        assertEquals("+998 90 123 45 67", Formats.formatPhone("+998901234567"))
        assertEquals("101", Formats.formatPhone("101"))
        assertEquals("25 000", Formats.formatMoney(25_000))
        assertEquals("-1 500", Formats.formatMoney(-1_500))
        assertEquals("01 A 123 BC", Formats.formatPlate("01A123BC"))
        assertEquals("01 123 ABC", Formats.formatPlate("01123abc"))
        assertEquals("T1234", Formats.formatPlate("T1234"))
    }
}
