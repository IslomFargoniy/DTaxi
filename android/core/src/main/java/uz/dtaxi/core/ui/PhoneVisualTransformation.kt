package uz.dtaxi.core.ui

import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.input.OffsetMapping
import androidx.compose.ui.text.input.TransformedText
import androidx.compose.ui.text.input.VisualTransformation
import uz.dtaxi.core.data.Phones

/** Milliy raqamlar → shu davlat formati ("901234567" → "90 123 45 67"): kursor raqamlar orasida to'g'ri siljiydi */
class PhoneVisualTransformation(private val country: String) : VisualTransformation {
    override fun filter(text: AnnotatedString): TransformedText {
        val digits = text.text
        val out = Phones.formatNational(country, digits)
        val mapping = object : OffsetMapping {
            override fun originalToTransformed(offset: Int): Int {
                if (offset <= 0) return 0
                var seen = 0
                out.forEachIndexed { i, c ->
                    if (c.isDigit() && ++seen == offset) return i + 1
                }
                return out.length
            }

            override fun transformedToOriginal(offset: Int): Int =
                out.take(offset).count(Char::isDigit).coerceIn(0, digits.length)
        }
        return TransformedText(AnnotatedString(out), mapping)
    }

    override fun equals(other: Any?): Boolean = other is PhoneVisualTransformation && other.country == country

    override fun hashCode(): Int = country.hashCode()
}
