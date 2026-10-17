import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
}

// Firma konfiguratsiyasi: -Pfirm=<papka> (qaror #35). Standart — lokal sinov uchun "dev".
val firm: String = (project.findProperty("firm") as String?) ?: "dev"
val firmDir = rootProject.file("firms/$firm")
val firmProps = Properties().apply {
    val file = File(firmDir, "firm.properties")
    require(file.exists()) { "firms/$firm/firm.properties topilmadi" }
    file.inputStream().use { load(it) }
}
fun firmProp(key: String, default: String = ""): String = firmProps.getProperty(key, default).trim()

val keystoreProps = Properties().apply {
    val file = File(firmDir, "keystore.properties")
    if (file.exists()) file.inputStream().use { load(it) }
}

// Tarmoq xavfsizligi: cleartext faqat http serverda (lokal sinov); firms/<firm>/dev-ca.pem bo'lsa —
// lokal sinov serverining self-signed sertifikatiga faqat debug build ishonadi (WebRTC qo'ng'iroq WSS uchun)
val devCa = File(firmDir, "dev-ca.pem")
val securityResDir = layout.buildDirectory.dir("generated/firmSecurityRes")
val generateNetworkSecurity by tasks.registering {
    val cleartext = firmProp("serverUrl").startsWith("http://")
    inputs.property("cleartext", cleartext)
    inputs.property("devCa", if (devCa.exists()) devCa.readText() else "")
    outputs.dir(securityResDir)
    doLast {
        val dir = securityResDir.get().asFile
        dir.deleteRecursively()
        File(dir, "xml").mkdirs()
        val debugAnchor = if (devCa.exists()) {
            File(dir, "raw").mkdirs()
            devCa.copyTo(File(dir, "raw/dev_ca.pem"), overwrite = true)
            "<certificates src=\"@raw/dev_ca\" />"
        } else {
            ""
        }
        File(dir, "xml/network_security_config.xml").writeText(
            """
            <?xml version="1.0" encoding="utf-8"?>
            <network-security-config>
                <base-config cleartextTrafficPermitted="$cleartext">
                    <trust-anchors><certificates src="system" /></trust-anchors>
                </base-config>
                <debug-overrides>
                    <trust-anchors>$debugAnchor<certificates src="system" /></trust-anchors>
                </debug-overrides>
            </network-security-config>
            """.trimIndent(),
        )
    }
}

android {
    namespace = "uz.ibtaxi.driver"
    compileSdk = 35

    defaultConfig {
        applicationId = firmProp("applicationId")
        minSdk = 29
        targetSdk = 35
        versionCode = (project.findProperty("versionCode") as String?)?.toInt() ?: 1
        versionName = (project.findProperty("versionName") as String?) ?: "1.0.0"

        resValue("string", "app_name", firmProp("appName", "ibTaxi Driver"))
        buildConfigField("String", "SERVER_URL", "\"${firmProp("serverUrl").trimEnd('/')}\"")
        buildConfigField("String", "PRIMARY_COLOR", "\"${firmProp("primaryColor", "#F5B700")}\"")
        buildConfigField("String", "FIREBASE_PROJECT_ID", "\"${firmProp("firebaseProjectId")}\"")
        buildConfigField("String", "FIREBASE_APPLICATION_ID", "\"${firmProp("firebaseApplicationId")}\"")
        buildConfigField("String", "FIREBASE_API_KEY", "\"${firmProp("firebaseApiKey")}\"")
        buildConfigField("String", "FIREBASE_SENDER_ID", "\"${firmProp("firebaseSenderId")}\"")
    }

    signingConfigs {
        if (keystoreProps.isNotEmpty()) {
            create("firm") {
                storeFile = File(firmDir, "keystore.jks")
                storePassword = keystoreProps.getProperty("storePassword")
                keyAlias = keystoreProps.getProperty("keyAlias")
                keyPassword = keystoreProps.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.findByName("firm") ?: signingConfigs.getByName("debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    sourceSets["main"].res.srcDir(securityResDir)
    buildFeatures {
        compose = true
        buildConfig = true
    }
    // Har protsessor turi uchun alohida APK — haydovchi telefoniga mosini server beradi (kichikroq yuklash)
    splits {
        abi {
            isEnable = true
            reset()
            include("arm64-v8a", "armeabi-v7a")
            isUniversalApk = false
        }
    }

    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.service)
    implementation(libs.androidx.activity.compose)
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.ui)
    implementation(libs.androidx.ui.graphics)
    implementation(libs.androidx.ui.tooling.preview)
    implementation(libs.androidx.material3)
    implementation(libs.androidx.material.icons.extended)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.androidx.security.crypto)
    implementation(libs.retrofit)
    implementation(libs.retrofit.converter.gson)
    implementation(libs.okhttp)
    implementation(libs.okhttp.logging)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.coroutines.play.services)
    implementation(libs.maplibre)
    implementation(libs.pusher)
    implementation(libs.play.services.location)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
    debugImplementation(libs.androidx.ui.tooling)
    testImplementation(libs.junit)
}

tasks.named("preBuild") { dependsOn(generateNetworkSecurity) }
