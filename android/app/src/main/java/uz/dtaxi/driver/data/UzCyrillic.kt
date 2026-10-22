package uz.dtaxi.driver.data

/**
 * O'zbek lotin → kirill (ilova ichida yasaladigan matnlar uchun: navigator ko'rsatmalari).
 * Qoidalar serverdagi UzbekCyrillic va android/tools/uz_cyrillic.py bilan bir xil.
 */
object UzCyrillic {
    private val letters = "abdfghijklmnopqrstuvxyze".zip("абдфгҳижклмнопқрстувхйзе").toMap()
    private val wordRegex = Regex("[A-Za-z][A-Za-z'ʻ‘’]*")

    fun convert(text: String): String = wordRegex.replace(text) { word(it.value.replace(Regex("[ʻ‘’]"), "'")) }

    private fun word(w: String): String {
        val bare = w.trimEnd('\'')
        if (Regex("[cC](?![hH])|[wW]").containsMatchIn(bare) || (bare.length in 2..5 && bare.all { it.isUpperCase() })) return w
        val low = w.lowercase()
        val out = StringBuilder()
        var i = 0
        while (i < low.length) {
            val c = low[i]
            val next = low.getOrNull(i + 1)
            when {
                (c == 'o' || c == 'g') && next == '\'' -> { out.append(if (c == 'o') 'ў' else 'ғ'); i += 2 }
                c == 's' && next == 'h' -> { out.append('ш'); i += 2 }
                c == 'c' && next == 'h' -> { out.append('ч'); i += 2 }
                c == 'y' && next in listOf('a', 'o', 'u', 'e') && !(next == 'o' && low.getOrNull(i + 2) == '\'') -> {
                    out.append(mapOf('a' to 'я', 'o' to 'ё', 'u' to 'ю', 'e' to 'е')[next]); i += 2
                }
                c == 'e' && (i == 0 || low[i - 1] in "aeiou") -> { out.append('э'); i++ }
                c == '\'' -> { out.append(if (i == low.length - 1) '\'' else 'ъ'); i++ }
                else -> { out.append(letters[c] ?: c); i++ }
            }
        }
        val s = out.toString()
        return if (w.first().isUpperCase()) s.replaceFirstChar { it.uppercase() } else s
    }
}
