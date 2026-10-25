package uz.dtaxi.core

import org.junit.Assert.assertEquals
import org.junit.Test
import uz.dtaxi.core.data.UzCyrillic

class UzCyrillicTest {
    @Test
    fun convertsUzbekLatinToCyrillic() {
        assertEquals("Ўзбекистон, Ғайрат — Қўнғироқ", UzCyrillic.convert("O'zbekiston, G'ayrat — Qo'ng'iroq"))
        assertEquals("Эртага йўл ёпиқ", UzCyrillic.convert("Ertaga yo'l yopiq"))
        assertEquals("Маълумот учун", UzCyrillic.convert("Ma'lumot uchun"))
        assertEquals("Ҳайдовчилар чати", UzCyrillic.convert("Haydovchilar chati"))
        assertEquals("Click орқали", UzCyrillic.convert("Click orqali"))
    }
}
