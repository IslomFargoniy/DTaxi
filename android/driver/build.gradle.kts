plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
}

// Firma konfiguratsiyasi (buildSrc/Firm.kt): -Pfirm=<papka>, standart — "dev"
val firm = Firm(project)
fun firmProp(key: String, default: String = ""): String = firm.prop(key, default)
firm.requireReleaseSigning()
val securityResDir = firm.registerNetworkSecurity()
val autoBuildNumber: Int = Firm.buildNumber(project)

android {
    namespace = "uz.dtaxi.driver"
    compileSdk = 35

    defaultConfig {
        applicationId = firmProp("applicationId")
        minSdk = 29
        targetSdk = 35
        versionCode = (project.findProperty("versionCode") as String?)?.toInt() ?: autoBuildNumber
        versionName = (project.findProperty("versionName") as String?) ?: "1.0.$autoBuildNumber"

        resValue("string", "app_name", firmProp("appName", "Dtaxi"))
        buildConfigField("String", "SERVER_URL", "\"${firmProp("serverUrl").trimEnd('/')}\"")
        // Faqat lokal sinov ilovasi: server manzili login ekranida o'zgartiriladi (Mac IP'si har Wi-Fi'da boshqa)
        buildConfigField("boolean", "SERVER_EDITABLE", firmProp("serverEditable", "false"))
        buildConfigField("String", "PRIMARY_COLOR", "\"${firmProp("primaryColor", "#FFC800")}\"")
        buildConfigField("String", "FIREBASE_PROJECT_ID", "\"${firmProp("firebaseProjectId")}\"")
        buildConfigField("String", "FIREBASE_APPLICATION_ID", "\"${firmProp("firebaseApplicationId")}\"")
        buildConfigField("String", "FIREBASE_API_KEY", "\"${firmProp("firebaseApiKey")}\"")
        buildConfigField("String", "FIREBASE_SENDER_ID", "\"${firmProp("firebaseSenderId")}\"")
    }

    signingConfigs {
        if (firm.signingReady) {
            create("firm") {
                storeFile = File(firm.dir, "keystore.jks")
                storePassword = firm.keystore.getProperty("storePassword")
                keyAlias = firm.keystore.getProperty("keyAlias")
                keyPassword = firm.keystore.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.findByName("firm") ?: signingConfigs.getByName("debug").takeIf { firm.debugSigningAllowed }
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
            // Bitta fayl hamma telefonlar uchun — Telegram orqali tarqatish qulay
            isUniversalApk = true
        }
    }

    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

dependencies {
    implementation(project(":core"))
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

// Har build'dan keyin universal APK public/android ga qisqa nom bilan: dtaxi.1.0.49.apk (debug: dtaxi.1.0.49-debug.apk).
// Papkada faqat oxirgi build turadi — eskilari o'chiriladi
val publicApkDir = rootProject.file("../public/android")
listOf("debug", "release").forEach { type ->
    val name = type.replaceFirstChar { it.uppercase() }
    val sync = tasks.register<Sync>("copy${name}ApkToPublic") {
        val version = android.defaultConfig.versionName
        from(layout.buildDirectory.dir("outputs/apk/$type")) { include("*-universal-*.apk") }
        into(publicApkDir)
        preserve { include("version.json") }
        rename { "dtaxi.$version" + (if (type == "debug") "-debug" else "") + ".apk" }
        // Ilova o'zini yangilashi uchun: oxirgi versiya ma'lumoti (ilova /android/version.json ni tekshiradi)
        val code = android.defaultConfig.versionCode
        val apkName = "dtaxi.$version" + (if (type == "debug") "-debug" else "") + ".apk"
        doLast {
            publicApkDir.resolve("version.json").writeText(
                "{\"version_code\": $code, \"version_name\": \"$version\", \"min_version_code\": 0, \"url\": \"/android/$apkName\"}\n",
            )
        }
    }
    tasks.matching { it.name == "assemble$name" }.configureEach { finalizedBy(sync) }
}
