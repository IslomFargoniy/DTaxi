package uz.dtaxi.core

import org.junit.Assert.assertEquals
import org.junit.Test
import uz.dtaxi.core.data.Formats
import uz.dtaxi.core.data.Phones

/** Server (tests/Feature/Admin/InputFormatTest.php) va panel bilan bir xil holatlar */
class FormatsTest {
    @Test
    fun typingKeepsCountryLengthAndPasteSwitchesCountry() {
        assertEquals("UZ" to "90123", Phones.input("UZ", "9012", "90123"))
        // 10-raqam O'zbekiston uchun ortiqcha — qo'shilmaydi
        assertEquals("UZ" to "901234567", Phones.input("UZ", "901234567", "9012345678"))
        assertEquals("UZ" to "901234567", Phones.input("UZ", "", "8 90 123 45 67"))
        assertEquals("UZ" to "901234567", Phones.input("UZ", "", "+998 90 123 45 67"))
        assertEquals("RU" to "9123456789", Phones.input("UZ", "", "+7 912 345-67-89"))
        assertEquals("KG" to "555123456", Phones.input("UZ", "", "00996555123456"))
    }

    @Test
    fun validityAndE164() {
        assertEquals(true, Phones.isValid("UZ", "901234567"))
        assertEquals(false, Phones.isValid("UZ", "90123"))
        assertEquals("+998901234567", Phones.e164("UZ", "901234567"))
        assertEquals("", Phones.e164("UZ", ""))
        assertEquals("90 123 45 67", Phones.formatNational("UZ", "901234567"))
    }

    @Test
    fun displayFormats() {
        assertEquals("+998 90 123 45 67", Formats.formatPhone("+998901234567"))
        assertEquals("+7 912 345 67 89", Formats.formatPhone("+79123456789"))
        assertEquals("101", Formats.formatPhone("101"))
        assertEquals("25 000", Formats.formatMoney(25_000))
        assertEquals("-1 500", Formats.formatMoney(-1_500))
        assertEquals("01 A 123 BC", Formats.formatPlate("01A123BC"))
        assertEquals("01 123 ABC", Formats.formatPlate("01123abc"))
        assertEquals("T1234", Formats.formatPlate("T1234"))
    }
}
